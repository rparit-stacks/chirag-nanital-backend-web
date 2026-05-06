<?php

namespace App\Services;

use App\Enums\SettingTypeEnum;
use App\Enums\Wallet\WalletTransactionTypeEnum;
use App\Enums\Wallet\WalletTypeEnum;
use App\Models\DeliveryBoy;
use App\Models\DeliveryBoyReferral;
use App\Models\DeliveryBoyReferralEarning;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DeliveryBoyReferralService
{
    protected SettingService $settingService;
    protected WalletService $walletService;

    public function __construct(SettingService $settingService, WalletService $walletService)
    {
        $this->settingService = $settingService;
        $this->walletService = $walletService;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 1. Code Generation
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Generate a unique referral code for a delivery boy.
     * Format: DBR-XXXXXXXX  (8 uppercase alphanumeric chars)
     * DBR- prefix ensures no collision with customer codes (REF-)
     */
    public function generateCode(): string
    {
        do {
            $code = 'DBR-' . strtoupper(Str::random(8));
        } while (DeliveryBoy::where('referral_code', $code)->exists());

        return $code;
    }

    /**
     * Generate and assign a referral code to a delivery boy if they don't have one.
     */
    public function generateAndAssignCode(DeliveryBoy $deliveryBoy): void
    {
        if (!$deliveryBoy->referral_code) {
            $deliveryBoy->update(['referral_code' => $this->generateCode()]);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 2. Settings Helper
    // ─────────────────────────────────────────────────────────────────────────

    public function getSettings(): array
    {
        try {
            $resource = $this->settingService->getSettingByVariable(SettingTypeEnum::DELIVERY_BOY());
            return $resource?->toArray(request())['value'] ?? [];
        } catch (\Throwable $e) {
            Log::error('DeliveryBoyReferralService: Failed to load settings — ' . $e->getMessage());
            return [];
        }
    }

    public function isEnabled(): bool
    {
        return (bool) ($this->getSettings()['deliveryBoyReferEarnStatus'] ?? false);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 3. Registration Hook — Create Pending Referral Record
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Called after a new delivery boy registers with a friends_code.
     * Creates a delivery_boy_referrals record with status = 'pending'.
     * NO wallet credit happens here — that only fires on admin verification.
     */
    public function handleRegistration(DeliveryBoy $newDb, ?string $friendsCode): void
    {
        if (!$friendsCode) {
            return;
        }

        try {
            // Program must be enabled
            if (!$this->isEnabled()) {
                Log::info("DeliveryBoyReferralService: Program disabled — skipping registration hook for DB [{$newDb->id}]");
                return;
            }

            // Find the referrer by their referral_code
            $referrer = DeliveryBoy::where('referral_code', $friendsCode)->first();

            if (!$referrer) {
                Log::warning("DeliveryBoyReferralService: No delivery boy found with referral_code [{$friendsCode}]");
                return;
            }

            // Self-referral guard
            if ($referrer->id === $newDb->id) {
                Log::warning("DeliveryBoyReferralService: Self-referral blocked for DB [{$newDb->id}]");
                return;
            }

            // Guard: DB-B can only be referred once (referred_id is UNIQUE in DB)
            if (DeliveryBoyReferral::where('referred_id', $newDb->id)->exists()) {
                return;
            }

            $allSettings = $this->getSettings();

            DeliveryBoyReferral::create([
                'referrer_id' => $referrer->id,
                'referred_id' => $newDb->id,
                'referral_code' => $friendsCode,
                'status' => 'pending',
                'settings' => [
                    'deliveryBoyReferEarnStatus' => $allSettings['deliveryBoyReferEarnStatus'] ?? false,
                    'deliveryBoyReferEarnBonusReferral' => $allSettings['deliveryBoyReferEarnBonusReferral'] ?? 0,
                    'deliveryBoyReferEarnBonusReferee' => $allSettings['deliveryBoyReferEarnBonusReferee'] ?? 0,
                ],
            ]);

            Log::info("DeliveryBoyReferralService: Referral created (pending) — referrer [{$referrer->id}] → referred [{$newDb->id}]");
        } catch (\Throwable $e) {
            Log::error("DeliveryBoyReferralService::handleRegistration — {$e->getMessage()}");
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 4. Verification Hook — Immediate Payout on Admin Approval
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Called when admin sets a delivery boy's verification_status to VERIFIED.
     * Immediately credits both referrer and referee wallets with fixed bonuses.
     * Creates audit earning rows and marks the referral as 'rewarded'.
     */
    public function handleVerification(DeliveryBoy $verifiedDb): void
    {
        try {
            // Find any open referral for this delivery boy:
            // - 'pending'   → first-time approval (normal path)
            // - 'cancelled' → was rejected before, admin re-approved (re-approval path)
            // We do NOT re-process already 'rewarded' referrals — those are final.
            $referral = DeliveryBoyReferral::where('referred_id', $verifiedDb->id)
                ->whereIn('status', ['pending', 'cancelled'])
                ->first();

            if (!$referral) {
                // No referral exists or already rewarded — nothing to pay
                Log::info("DeliveryBoyReferralService: No payable referral for DB [{$verifiedDb->id}] — skipping.");
                return;
            }

            // Check if program was enabled at the time the referral was created (from snapshot)
            if (empty($referral->settings['deliveryBoyReferEarnStatus'])) {
                Log::info("DeliveryBoyReferralService: Program was disabled at time of referral — referral [{$referral->id}] stays {$referral->status}");
                return;
            }

            // Use snapshot settings if available, otherwise fall back to live settings
            $settings = $referral->settings ?? $this->getSettings();
            $bonusReferral = (float) ($settings['deliveryBoyReferEarnBonusReferral'] ?? 0);
            $bonusReferee = (float) ($settings['deliveryBoyReferEarnBonusReferee'] ?? 0);

            DB::transaction(function () use ($referral, $bonusReferral, $bonusReferee) {
                // ── Referrer (DB-A) wallet credit ──────────────────────────
                if ($bonusReferral > 0) {
                    $resultA = $this->walletService->addBalance($referral->referrer->user_id, [
                        'amount' => $bonusReferral,
                        'payment_method' => 'referral',
                        'description' => __('labels.db_referral_bonus_referrer') ?? 'Delivery boy referral bonus — you referred a new member',
                        'type' => WalletTransactionTypeEnum::DELIVERY_BOY_REFERRAL_BONUS->value,
                        'transaction_reference' => 'db_referral_earn_referrer_' . $referral->id,
                    ], WalletTypeEnum::DELIVERY_BOY);

                    $txIdA = $resultA['data']['transaction_id'] ?? null;

                    DeliveryBoyReferralEarning::create([
                        'referral_id' => $referral->id,
                        'beneficiary_id' => $referral->referrer_id,
                        'beneficiary_type' => 'referrer',
                        'bonus_amount' => $bonusReferral,
                        'wallet_transaction_id' => $txIdA,
                        'settled_at' => now(),
                    ]);
                }

                // ── Referee (DB-B) wallet credit ───────────────────────────
                if ($bonusReferee > 0) {
                    $resultB = $this->walletService->addBalance($referral->referred->user_id, [
                        'amount' => $bonusReferee,
                        'payment_method' => 'referral',
                        'description' => __('labels.db_referral_bonus_referee') ?? 'Welcome referral bonus for joining via a referral code',
                        'type' => WalletTransactionTypeEnum::DELIVERY_BOY_REFERRAL_BONUS->value,
                        'transaction_reference' => 'db_referral_earn_referee_' . $referral->id,
                    ], WalletTypeEnum::DELIVERY_BOY);

                    $txIdB = $resultB['data']['transaction_id'] ?? null;

                    DeliveryBoyReferralEarning::create([
                        'referral_id' => $referral->id,
                        'beneficiary_id' => $referral->referred_id,
                        'beneficiary_type' => 'referee',
                        'bonus_amount' => $bonusReferee,
                        'wallet_transaction_id' => $txIdB,
                        'settled_at' => now(),
                    ]);
                }

                // Mark referral as rewarded (final — cannot be changed back)
                $referral->update([
                    'status' => 'rewarded',
                    'rewarded_at' => now(),
                ]);
            });

            Log::info("DeliveryBoyReferralService: Referral [{$referral->id}] rewarded — referrer [{$referral->referrer_id}] +{$bonusReferral}, referee [{$referral->referred_id}] +{$bonusReferee}");
        } catch (\Throwable $e) {
            Log::error("DeliveryBoyReferralService::handleVerification — {$e->getMessage()}");
            throw $e;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 5. Rejection Hook — Mark Referral Cancelled
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Called when admin rejects a delivery boy.
     * Marks the referral as 'cancelled' — no bonus paid.
     */
    public function handleRejection(DeliveryBoy $rejectedDb): void
    {
        try {
            DeliveryBoyReferral::where('referred_id', $rejectedDb->id)
                ->where('status', 'pending')
                ->update(['status' => 'cancelled']);

            Log::info("DeliveryBoyReferralService: Referral cancelled for rejected DB [{$rejectedDb->id}]");
        } catch (\Throwable $e) {
            Log::error("DeliveryBoyReferralService::handleRejection — {$e->getMessage()}");
        }
    }
}
