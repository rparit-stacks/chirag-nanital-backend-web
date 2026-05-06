<?php

namespace App\Http\Controllers\Api\DeliveryBoy;

use App\Http\Controllers\Controller;
use App\Models\DeliveryBoy;
use App\Models\DeliveryBoyReferral;
use App\Models\DeliveryBoyReferralEarning;
use App\Types\Api\ApiResponseType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Services\DeliveryBoyReferralService;
use App\Services\SettingService;

class DeliveryBoyReferralApiController extends Controller
{
    protected DeliveryBoyReferralService $referralService;

    public function __construct(DeliveryBoyReferralService $referralService)
    {
        $this->referralService = $referralService;
    }
    /**
     * GET /api/delivery-boy/referral
     *
     * Returns the authenticated delivery boy's referral code,
     * program status, and summary stats.
     */
    public function info(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $settings = $this->referralService->getSettings();
            $deliveryBoy = DeliveryBoy::where('user_id', $user->id)->first();
            if (empty($deliveryBoy->referral_code)) {
                $deliveryBoy->referral_code = $this->referralService->generateCode();
                $deliveryBoy->save();
            }
            if (!$deliveryBoy) {
                return ApiResponseType::sendJsonResponse(
                    success: false,
                    message: __('labels.not_a_delivery_boy'),
                    data: []
                );
            }

            $totalReferrals = DeliveryBoyReferral::where('referrer_id', $deliveryBoy->id)->count();
            $rewardedCount = DeliveryBoyReferral::where('referrer_id', $deliveryBoy->id)
                ->where('status', 'rewarded')->count();

            $totalEarned = DeliveryBoyReferralEarning::where('beneficiary_id', $deliveryBoy->id)
                ->sum('bonus_amount');

            return ApiResponseType::sendJsonResponse(
                success: true,
                message: __('labels.referral_info_retrieved'),
                data: [
                    'referral_code' => $deliveryBoy->referral_code,
                    'total_referrals' => $totalReferrals,
                    'rewarded' => $rewardedCount,
                    'pending' => $totalReferrals - $rewardedCount,
                    'total_earned' => round((float) $totalEarned, 2),
                    'program' => [
                        'status' => (bool) ($settings['deliveryBoyReferEarnStatus'] ?? false),
                        'bonus_referral' => (float) ($settings['deliveryBoyReferEarnBonusReferral'] ?? 0),
                        'bonus_referee' => (float) ($settings['deliveryBoyReferEarnBonusReferee'] ?? 0),
                    ],
                ]
            );
        } catch (\Exception $e) {
            return ApiResponseType::sendJsonResponse(
                success: false,
                message: __('labels.something_went_wrong'),
                data: ['error' => $e->getMessage()]
            );
        }
    }

    /**
     * GET /api/delivery-boy/referral/earnings
     *
     * Returns paginated earning history for the authenticated delivery boy.
     */
    public function earnings(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $deliveryBoy = DeliveryBoy::where('user_id', $user->id)->first();

            if (!$deliveryBoy) {
                return ApiResponseType::sendJsonResponse(
                    success: false,
                    message: __('labels.not_a_delivery_boy'),
                    data: []
                );
            }

            $earnings = DeliveryBoyReferralEarning::where('beneficiary_id', $deliveryBoy->id)
                ->with(['referral.referrer', 'referral.referred'])
                ->latest('settled_at')
                ->paginate(15);

            $data = $earnings->map(function ($e) use ($deliveryBoy) {
                $other = $e->beneficiary_type === 'referrer'
                    ? $e->referral?->referred
                    : $e->referral?->referrer;

                return [
                    'id' => $e->id,
                    'type' => $e->beneficiary_type,
                    'bonus_amount' => (float) $e->bonus_amount,
                    'settled_at' => $e->settled_at?->toDateTimeString(),
                    'other_party_name' => $other?->full_name ?? 'N/A',
                ];
            });

            return ApiResponseType::sendJsonResponse(
                success: true,
                message: __('labels.earnings_retrieved'),
                data: [
                    'earnings' => $data,
                    'current_page' => $earnings->currentPage(),
                    'last_page' => $earnings->lastPage(),
                    'total' => $earnings->total(),
                ]
            );
        } catch (\Exception $e) {
            return ApiResponseType::sendJsonResponse(
                success: false,
                message: __('labels.something_went_wrong'),
                data: ['error' => $e->getMessage()]
            );
        }
    }
}
