<?php

namespace App\Http\Controllers\Api\User;

use App\Enums\SettingTypeEnum;
use App\Http\Controllers\Controller;
use App\Models\Referral;
use App\Models\ReferralEarning;
use App\Models\User;
use App\Services\ReferralService;
use App\Services\SettingService;
use App\Types\Api\ApiResponseType;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

#[Group('Referral')]
class ReferralApiController extends Controller
{
    protected ReferralService $referralService;
    protected SettingService $settingService;

    public function __construct(ReferralService $referralService, SettingService $settingService)
    {
        $this->referralService = $referralService;
        $this->settingService = $settingService;
    }

    /**
     * Get the authenticated user's referral info and program configuration.
     *
     * Returns:
     *  - user's own referral_code, friends_code, total earned
     *  - program settings (is enabled, bonus amounts, method, min order, etc.)
     *  - referral stats (total referrals, pending / settled earnings)
     */
    public function getReferralInfo(): JsonResponse
    {
        try {
            $user = Auth::user();
            $settings = $this->referralService->getReferralSettings();
            // Check if user has referral code, if not generate one (for legacy users who signed up before referral program was enabled)
            if (empty($user->referral_code)) {
                $user->referral_code = $this->referralService->generateCode();
                $user->save();
            }

            if (!($settings['referEarnStatus'] ?? false)) {
                return ApiResponseType::sendJsonResponse(
                    false,
                    'labels.referral_program_not_enabled',
                    []
                );
            }

            // Referral record if this user was referred by someone
            $referral = Referral::where('referred_id', $user->id)->first();

            // Stats: how many users this person has referred
            $totalReferrals = Referral::where('referrer_id', $user->id)->count();

            // Earnings as referrer
            $totalEarned = ReferralEarning::where('beneficiary_id', $user->id)
                ->where('status', 'earned')
                ->sum('earned_amount');

            $pendingEarnings = ReferralEarning::where('beneficiary_id', $user->id)
                ->where('status', 'pending')
                ->sum('earned_amount');

            return ApiResponseType::sendJsonResponse(
                true,
                'labels.referral_info_retrieved',
                [
                    'referral_code' => $user->referral_code,
                    'friends_code' => $user->friends_code,
                    'total_referrals' => $totalReferrals,
                    'total_earned' => round((float) $totalEarned, 2),
                    'pending_earnings' => round((float) $pendingEarnings, 2),
                    'program' => [
                        'status' => (bool) ($settings['referEarnStatus'] ?? false),
                        'referrer_bonus_method' => $settings['referEarnMethodReferral'] ?? '',
                        'referrer_bonus_value' => $settings['referEarnBonusReferral'] ?? '',
                        'referrer_bonus_max_cap' => $settings['referEarnMaximumBonusAmountReferral'] ?? '',
                        'referee_bonus_method' => $settings['referEarnMethodUser'] ?? '',
                        'referee_bonus_value' => $settings['referEarnBonusUser'] ?? '',
                        'referee_bonus_max_cap' => $settings['referEarnMaximumBonusAmountUser'] ?? '',
                        'minimum_order_amount' => $settings['referEarnMinimumOrderAmount'] ?? '',
                        'max_times_bonus' => $settings['referEarnNumberOfTimesBonus'] ?? '',
                    ],
                ]
            );
        } catch (\Exception $e) {
            return ApiResponseType::sendJsonResponse(
                false,
                'labels.something_went_wrong',
                ['error' => $e->getMessage()]
            );
        }
    }

    /**
     * Apply a referral code post-registration (if the user forgot to enter it during signup).
     * A user can only apply a referral code ONCE and cannot use their own code.
     */
    public function applyReferralCode(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();

            // Check if program is enabled
            if (!$this->referralService->isEnabled()) {
                return ApiResponseType::sendJsonResponse(
                    false,
                    'labels.referral_program_not_enabled',
                    []
                );
            }

            // Check if user already has a friends_code
            if (!empty($user->friends_code)) {
                return ApiResponseType::sendJsonResponse(
                    false,
                    'labels.referral_code_already_applied',
                    []
                );
            }

            // Check if user already has a referral record (was already referred)
            $alreadyReferred = Referral::where('referred_id', $user->id)->exists();
            if ($alreadyReferred) {
                return ApiResponseType::sendJsonResponse(
                    false,
                    'labels.referral_code_already_applied',
                    []
                );
            }

            $request->validate([
                'friends_code' => 'required|string|max:32|exists:users,referral_code',
            ]);

            $friendsCode = $request->input('friends_code');

            // Self-referral guard
            if ($user->referral_code === $friendsCode) {
                return ApiResponseType::sendJsonResponse(
                    false,
                    'labels.referral_self_not_allowed',
                    []
                );
            }

            // Save friends_code on user
            $user->update(['friends_code' => $friendsCode]);
            $user->refresh();

            // Create the referral record
            $this->referralService->handleRegistration($user, $friendsCode);

            return ApiResponseType::sendJsonResponse(
                true,
                'labels.referral_code_applied_successfully',
                ['friends_code' => $friendsCode]
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
            return ApiResponseType::sendJsonResponse(
                false,
                'labels.validation_error',
                ['errors' => $e->errors()]
            );
        } catch (\Exception $e) {
            Log::error('ReferralApiController::applyReferralCode — ' . $e->getMessage());
            return ApiResponseType::sendJsonResponse(
                false,
                'labels.something_went_wrong',
                ['error' => $e->getMessage()]
            );
        }
    }

    /**
     * Get the authenticated user's referral earnings history (paginated).
     */
    public function getEarningsHistory(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            $perPage = (int) $request->get('per_page', 15);

            $earnings = ReferralEarning::where('beneficiary_id', $user->id)
                ->with(['order:id,slug,final_total,created_at'])
                ->orderByDesc('created_at')
                ->paginate($perPage);

            $data = $earnings->map(function (ReferralEarning $e) {
                return [
                    'id' => $e->id,
                    'order_id' => $e->order_id,
                    'order_amount' => (float) $e->order_amount,
                    'bonus_method' => $e->bonus_method,
                    'bonus_value' => (float) $e->bonus_value,
                    'earned_amount' => (float) $e->earned_amount,
                    'beneficiary_type' => $e->beneficiary_type,
                    'status' => $e->status,
                    'eligible_at' => $e->eligible_at?->format('Y-m-d'),
                    'settled_at' => $e->settled_at?->format('Y-m-d H:i:s'),
                    'reversed_at' => $e->reversed_at?->format('Y-m-d H:i:s'),
                    'created_at' => $e->created_at?->format('Y-m-d H:i:s'),
                ];
            });

            return ApiResponseType::sendJsonResponse(
                true,
                'labels.earnings_retrieved',
                [
                    'earnings' => $data,
                    'total' => $earnings->total(),
                    'current_page' => $earnings->currentPage(),
                    'last_page' => $earnings->lastPage(),
                ]
            );
        } catch (\Exception $e) {
            return ApiResponseType::sendJsonResponse(
                false,
                'labels.something_went_wrong',
                ['error' => $e->getMessage()]
            );
        }
    }
}
