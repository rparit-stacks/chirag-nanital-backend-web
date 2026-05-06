<?php

namespace App\Services;

use App\Enums\Order\OrderStatusEnum;
use App\Enums\SettingTypeEnum;
use App\Enums\Wallet\WalletTransactionTypeEnum;
use App\Enums\ReferEarnMethodEnum;
use App\Enums\Referral\ReferralBeneficiaryTypeEnum;
use App\Enums\Referral\ReferralEarningStatusEnum;
use App\Enums\Referral\ReferralStatusEnum;
use App\Enums\Order\OrderItemReturnStatusEnum;
use App\Models\Order;
use App\Models\Referral;
use App\Models\ReferralEarning;
use App\Models\User;
use Composer\XdebugHandler\Status;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Collection;


class ReferralService
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
     * Generate a unique referral code for a user.
     * Format: REF-XXXXXXXX  (8 uppercase alphanumeric chars)
     */
    public function generateCode(): string
    {
        do {
            $code = 'REF-' . strtoupper(Str::random(8));
        } while (User::where('referral_code', $code)->exists());

        return $code;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 2. Settings Helper
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Fetch all referral-related system settings as a plain array.
     */
    public function getReferralSettings(): array
    {
        try {
            $resource = $this->settingService->getSettingByVariable(SettingTypeEnum::SYSTEM());
            return $resource?->toArray(request())['value'] ?? [];
        } catch (\Throwable $e) {
            Log::error('ReferralService: Failed to load settings — ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Check if the Refer & Earn program is enabled in admin settings.
     */
    public function isEnabled(): bool
    {
        return (bool) ($this->getReferralSettings()['referEarnStatus'] ?? false);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 3. Create Referral Link on Registration
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Called after a new user registers with a friends_code.
     * Creates a referrals record linking referrer (owner of code) → referred (new user).
     *
     * @param User $newUser       The newly registered user
     * @param string $friendsCode The code the new user entered
     */
    public function handleRegistration(User $newUser, string $friendsCode): void
    {
        try {
            if (!$this->isEnabled()) {
                return;
            }

            // Find the referrer by their referral_code
            $referrer = User::where('referral_code', $friendsCode)->first();

            if (!$referrer) {
                Log::warning("ReferralService: No user found with referral_code [{$friendsCode}] for new user [{$newUser->id}]");
                return;
            }

            // Self-referral guard
            if ($referrer->id === $newUser->id) {
                Log::warning("ReferralService: Self-referral attempt blocked for user [{$newUser->id}]");
                return;
            }

            // Avoid duplicate referral records (referred_id is unique in the DB anyway)
            $exists = Referral::where('referred_id', $newUser->id)->exists();
            if ($exists) {
                return;
            }

            $allSettings = $this->getReferralSettings();

            Referral::create([
                'referrer_id' => $referrer->id,
                'referred_id' => $newUser->id,
                'referral_code' => $friendsCode,
                'status' => ReferralStatusEnum::PENDING(),
                'settings' => [
                    'referEarnStatus' => $allSettings['referEarnStatus'] ?? false,
                    'referEarnMinimumOrderAmount' => $allSettings['referEarnMinimumOrderAmount'] ?? 0,
                    'referEarnNumberOfTimesBonus' => $allSettings['referEarnNumberOfTimesBonus'] ?? 1,
                    'referEarnMethodReferral' => $allSettings['referEarnMethodReferral'] ?? null,
                    'referEarnBonusReferral' => $allSettings['referEarnBonusReferral'] ?? 0,
                    'referEarnMaximumBonusAmountReferral' => $allSettings['referEarnMaximumBonusAmountReferral'] ?? 0,
                    'referEarnMethodUser' => $allSettings['referEarnMethodUser'] ?? null,
                    'referEarnBonusUser' => $allSettings['referEarnBonusUser'] ?? 0,
                    'referEarnMaximumBonusAmountUser' => $allSettings['referEarnMaximumBonusAmountUser'] ?? 0,
                ],
            ]);

            Log::info("ReferralService: Referral created — referrer [{$referrer->id}] → referred [{$newUser->id}]");
        } catch (\Throwable $e) {
            Log::error("ReferralService::handleRegistration — {$e->getMessage()}");
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 4. Settle Eligible Earnings (called by Artisan command)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Settle eligible referral earnings for completed orders.
     * Uses chunkById to process referrals in batches — never loads all referrals into memory.
     */
    public function settleEligibleEarnings(): array
    {
        $settled = 0;
        $errors = 0;

        try {
            Referral::whereIn('status', [
                ReferralStatusEnum::PENDING(),
                ReferralStatusEnum::ACTIVE(),
            ])
                ->whereNotNull('settings')
                ->with([
                    'referred.orders' => function ($q) {
                        $q->whereNotIn('id', function ($sub) {
                            $sub->select('order_id')
                                ->from('referral_earnings');
                        })
                            ->whereDoesntHave('items', function ($itemQ) {
                                $itemQ->whereIn('status', [
                                    \App\Enums\Order\OrderItemStatusEnum::PENDING(),
                                    \App\Enums\Order\OrderItemStatusEnum::AWAITING_STORE_RESPONSE(),
                                    \App\Enums\Order\OrderItemStatusEnum::ACCEPTED(),
                                    \App\Enums\Order\OrderItemStatusEnum::PREPARING(),
                                    \App\Enums\Order\OrderItemStatusEnum::COLLECTED(),
                                ]);
                            })
                            ->whereDoesntHave('returns', function ($retQ) {
                                $retQ->whereNotIn('return_status', [
                                    OrderItemReturnStatusEnum::CANCELLED(),
                                    OrderItemReturnStatusEnum::SELLER_REJECTED(),
                                    OrderItemReturnStatusEnum::REFUND_PROCESSED(),
                                    OrderItemReturnStatusEnum::COMPLETED(),
                                ]);
                            })
                            ->with([
                                'items',
                                'returns' => function ($r) {
                                    $r->whereIn('return_status', [
                                        OrderItemReturnStatusEnum::REFUND_PROCESSED(),
                                        OrderItemReturnStatusEnum::COMPLETED(),
                                    ]);
                                }
                            ]);
                    }
                ])
                ->chunkById(50, function (Collection $referrals) use (&$settled, &$errors) {
                    foreach ($referrals as $referral) {
                        $this->processReferral($referral, $settled, $errors);
                    }
                });
        } catch (\Throwable $e) {
            Log::error("ReferralService::settleEligibleEarnings — " . $e->getMessage());
        }

        return ['settled' => $settled, 'errors' => $errors];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private Helpers — Settlement Pipeline
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Process a single referral — find its eligible orders and settle each one.
     */
    private function processReferral(Referral $referral, int &$settled, int &$errors): void
    {
        $settings = $referral->settings;
        if (empty($settings)) {
            return;
        }

        $eligibleOrders = $this->getEligibleOrders($referral);

        foreach ($eligibleOrders as $order) {
            $this->settleOrderReward($referral, $order, $settings, $settled, $errors);
        }
    }

    /**
     * Filter the eager-loaded orders to only those fully ready for settlement.
     * (Return window expired, items non-empty, etc.)
     */
    private function getEligibleOrders(Referral $referral): \Illuminate\Support\Collection
    {
        $orders = $referral->referred->orders ?? collect();

        return $orders->filter(function (Order $order) {
            if ($order->items->isEmpty()) {
                return false;
            }
            return $this->isOrderReadyForSettlement($order);
        });
    }

    /**
     * Check if an order's return window has fully expired and it is ready for settlement.
     */
    private function isOrderReadyForSettlement(Order $order): bool
    {
        $deliveredItems = $order->items->filter(
            fn($item) => $item->status === \App\Enums\Order\OrderItemStatusEnum::DELIVERED()
        );
        $maxDeadline = $deliveredItems->whereNotNull('return_deadline')->max('return_deadline');

        // Return window still open
        if ($maxDeadline && \Carbon\Carbon::parse($maxDeadline)->endOfDay()->isFuture()) {
            return false;
        }

        return true;
    }

    /**
     * Calculate the valid subtotal after deducting approved return refunds.
     */
    private function calculateValidSubtotal(Order $order): float
    {
        $approvedReturnsSum = $order->returns->sum('refund_amount');
        return (float) $order->subtotal - (float) $approvedReturnsSum;
    }

    /**
     * Check if this referral has already reached its max reward count (from snapshot).
     */
    private function hasReachedMaxRewards(Referral $referral, int $maxTimes): bool
    {
        $qualifyingCount = ReferralEarning::where('referral_id', $referral->id)
            ->where('status', ReferralEarningStatusEnum::SUCCESS())
            ->where('beneficiary_type', ReferralBeneficiaryTypeEnum::REFERRER())
            ->count();

        return $qualifyingCount >= $maxTimes;
    }

    /**
     * Validate and settle a single order's referral reward for both referrer and referee.
     */
    private function settleOrderReward(Referral $referral, Order $order, array $settings, int &$settled, int &$errors): void
    {
        $validSubtotal = $this->calculateValidSubtotal($order);
        $minOrderAmount = (float) ($settings['referEarnMinimumOrderAmount'] ?? 0);
        $maxTimes = (int) ($settings['referEarnNumberOfTimesBonus'] ?? 1);

        // Check if order is too old (30-day retroactive guard)
        $deliveredItems = $order->items->filter(
            fn($item) => $item->status === \App\Enums\Order\OrderItemStatusEnum::DELIVERED()
        );
        $maxDeadline = $deliveredItems->whereNotNull('return_deadline')->max('return_deadline');
        $validationDate = $maxDeadline ? \Carbon\Carbon::parse($maxDeadline)->endOfDay() : $order->updated_at;

        // if ($validationDate->copy()->addDays(30)->isPast()) {
        //     $this->createFailedEarningRow($referral, $order, $validSubtotal);
        //     return;
        // }

        // Check minimum order amount
        if ($validSubtotal < $minOrderAmount || $validSubtotal <= 0) {
            // $this->createFailedEarningRow($referral, $order, $validSubtotal);
            Log::info("ReferralService: [{$referral->id}] Order subtotal is less than minimum order amount");
            return;
        }

        // Check max reward capacity from snapshot
        if ($this->hasReachedMaxRewards($referral, $maxTimes)) {
            // $this->createFailedEarningRow($referral, $order, $validSubtotal);
            Log::info("ReferralService: [{$referral->id}] Order has reached max rewards");
            $this->checkAndCompleteReferral($referral);
            return;
        }

        // Execute settlement
        try {
            DB::transaction(function () use ($referral, $order, $validSubtotal, $settings, &$settled) {
                $this->calculateAndSettle(
                    $referral,
                    $order,
                    $validSubtotal,
                    $referral->referrer_id,
                    ReferralBeneficiaryTypeEnum::REFERRER(),
                    $settings['referEarnMethodReferral'] ?? ReferEarnMethodEnum::Fixed->value,
                    (float) ($settings['referEarnBonusReferral'] ?? 0),
                    (float) ($settings['referEarnMaximumBonusAmountReferral'] ?? 0)
                );

                $this->calculateAndSettle(
                    $referral,
                    $order,
                    $validSubtotal,
                    $referral->referred_id,
                    ReferralBeneficiaryTypeEnum::REFEREE(),
                    $settings['referEarnMethodUser'] ?? ReferEarnMethodEnum::Fixed->value,
                    (float) ($settings['referEarnBonusUser'] ?? 0),
                    (float) ($settings['referEarnMaximumBonusAmountUser'] ?? 0)
                );

                if ($referral->status === ReferralStatusEnum::PENDING()) {
                    $referral->update(['status' => ReferralStatusEnum::ACTIVE(), 'rewarded_at' => now()]);
                }

                $this->checkAndCompleteReferral($referral);
                $settled++;
            });
            Log::info("ReferralService: Settled order [{$order->id}] for referral [{$referral->id}]");
        } catch (\Throwable $e) {
            Log::error("ReferralService: Failed to settle order [{$order->id}] — " . $e->getMessage());
            $errors++;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private Helpers — Earning Records & Wallet
    // ─────────────────────────────────────────────────────────────────────────

    private function createFailedEarningRow(Referral $referral, Order $order, float $subtotal): void
    {
        ReferralEarning::create([
            'referral_id' => $referral->id,
            'beneficiary_id' => $referral->referrer_id,
            'beneficiary_type' => ReferralBeneficiaryTypeEnum::REFERRER(),
            'order_id' => $order->id,
            'order_amount' => $subtotal,
            'bonus_method' => ReferEarnMethodEnum::Fixed->value,
            'bonus_value' => 0,
            'max_cap' => null,
            'earned_amount' => 0,
            'status' => ReferralEarningStatusEnum::FAILED()
        ]);
        Log::info("ReferralService: Earning marked 'failed' for order [{$order->id}]");
    }

    private function calculateAndSettle(Referral $referral, Order $order, float $orderTotal, int $beneficiaryId, string $type, string $method, float $bonusValue, float $maxCap): void
    {
        if ($bonusValue <= 0) {
            return;
        }

        $earnedAmount = ($method === ReferEarnMethodEnum::Percentage->value) ? ($orderTotal * $bonusValue) / 100 : $bonusValue;
        if ($maxCap > 0) {
            $earnedAmount = min($earnedAmount, $maxCap);
        }
        $earnedAmount = round($earnedAmount, 2);

        if ($earnedAmount <= 0) {
            return;
        }

        $earning = ReferralEarning::create([
            'referral_id' => $referral->id,
            'beneficiary_id' => $beneficiaryId,
            'beneficiary_type' => $type,
            'order_id' => $order->id,
            'order_amount' => $orderTotal,
            'bonus_method' => $method,
            'bonus_value' => $bonusValue,
            'max_cap' => $maxCap > 0 ? $maxCap : null,
            'earned_amount' => $earnedAmount,
            'status' => ReferralEarningStatusEnum::PENDING()
        ]);

        $result = $this->walletService->addBalance($beneficiaryId, [
            'amount' => $earnedAmount,
            'payment_method' => 'wallet',
            'description' => $this->buildWalletDescription($earning),
            'type' => WalletTransactionTypeEnum::REFERRAL_BONUS->value,
            'transaction_reference' => 'referral_earn_' . $earning->id
        ]);

        if (!($result['success'] ?? false)) {
            $earning->update(['status' => ReferralEarningStatusEnum::FAILED()]);
            throw new \RuntimeException("Wallet credit failed: " . ($result['message'] ?? 'unknown'));
        }

        $earning->update([
            'status' => ReferralEarningStatusEnum::SUCCESS(),
            'settled_at' => now(),
            'wallet_transaction_id' => $result['data']['transaction_id'] ?? null,
        ]);
    }

    private function checkAndCompleteReferral(Referral $referral): void
    {
        $settings = $referral->settings;
        if (empty($settings)) {
            return;
        }

        $maxTimes = (int) ($settings['referEarnNumberOfTimesBonus'] ?? 1);

        $settledOrderCount = ReferralEarning::where('referral_id', $referral->id)
            ->where('status', ReferralEarningStatusEnum::SUCCESS())
            ->where('beneficiary_type', ReferralBeneficiaryTypeEnum::REFERRER())
            ->count();

        if ($settledOrderCount >= $maxTimes) {
            $referral->update(['status' => ReferralStatusEnum::COMPLETED(), 'completed_at' => now()]);
        }
    }

    private function buildWalletDescription(ReferralEarning $earning): string
    {
        $type = $earning->beneficiary_type === ReferralBeneficiaryTypeEnum::REFERRER()
            ? __('labels.referral_bonus_referrer')
            : __('labels.referral_bonus_referee');

        return "{$type} (Order #" . ($earning->order->slug ?? $earning->order_id) . ")";
    }
}
