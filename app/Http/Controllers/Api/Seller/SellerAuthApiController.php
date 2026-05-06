<?php

namespace App\Http\Controllers\Api\Seller;

use App\Enums\Seller\SellerVerificationStatusEnum;
use App\Enums\Seller\SellerVisibilityStatusEnum;
use App\Http\Requests\Seller\StoreSellerRequest;
use App\Services\SellerService;
use App\Traits\AuthTrait;
use App\Types\Api\ApiResponseType;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use App\Enums\SellerPermissionEnum;

#[Group('Seller Authentication')]
class SellerAuthApiController
{
    use AuthTrait;

    protected string $role = 'seller';

    protected $sellerService;

    public function __construct(SellerService $sellerService)
    {
        $this->sellerService = $sellerService;
    }

    /**
     * creating sellers API
     */
    public function createSeller(StoreSellerRequest $request): JsonResponse
    {
        // Restrict seller self-registration in Single Vendor mode
        if (Setting::isSystemVendorTypeSingle()) {
            return ApiResponseType::sendJsonResponse(
                success: false,
                message: __('labels.seller_registration_disabled'),
                status: 403
            );
        }

        try {
            $validated = $request->validated();
            $validated['verification_status'] = SellerVisibilityStatusEnum::Draft();
            $validated['visibility_status'] = SellerVerificationStatusEnum::NotApproved();
            $seller = $this->sellerService->createSeller(
                $validated,
                $request->allFiles()
            );

            return ApiResponseType::sendJsonResponse(
                true,
                'labels.seller_created_successfully',
                $seller,
                201
            );
        } catch (ValidationException $e) {
            return ApiResponseType::sendJsonResponse(
                success: false,
                message: 'labels.validation_failed' . $e->getMessage(),
                data: $e->errors(),
            );
        } catch (\Exception $e) {
            return ApiResponseType::sendJsonResponse(
                success: true,
                message: 'labels.seller_created_successfully',
                status: 500
            );
        }
    }

    /**
     * Delete Seller Account
     */
    public function deleteAccount(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $seller = $user?->seller();

            if (!$seller) {
                return ApiResponseType::sendJsonResponse(false, __('labels.seller_not_found'), null, 404);
            }

            // Validate permission: either user is the main seller, or has SELLER_DELETE permission
            if ((int) $seller->user_id !== (int) $user->id) {
                if (function_exists('setPermissionsTeamId')) {
                    setPermissionsTeamId($seller->id);
                }
                if (!$user->hasPermissionTo(SellerPermissionEnum::SELLER_DELETE())) {
                    return ApiResponseType::sendJsonResponse(false, __('labels.permission_denied'), [], 403);
                }
            }

            DB::beginTransaction();
            $seller->delete();
            $seller->media()->each(function ($media) {
                $media->delete();
            });
            $user->delete();
            DB::commit();

            return ApiResponseType::sendJsonResponse(true, __('labels.account_deleted_successfully'), []);
        } catch (\Exception $e) {
            DB::rollBack();
            return ApiResponseType::sendJsonResponse(false, __('labels.account_deletion_failed', ['error' => $e->getMessage()]), []);
        }
    }
}
