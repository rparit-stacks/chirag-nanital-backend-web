<?php

namespace App\Http\Controllers\Api\DeliveryBoy;

use App\Enums\Wallet\WalletTypeEnum;
use App\Http\Controllers\Controller;
use App\Http\Resources\User\WalletResource;
use App\Http\Resources\User\WalletTransactionResource;
use App\Services\WalletService;
use App\Types\Api\ApiResponseType;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('DeliveryBoy Wallet')]
class DeliveryBoyWalletApiController extends Controller
{
    public function __construct(protected WalletService $walletService)
    {
    }

    /**
     * Get authenticated delivery boy wallet balance/details.
     *
     * Always scoped to the DELIVERY_BOY wallet so rider earnings stay
     * isolated from any customer-panel wallet the same user may own.
     *
     * @return JsonResponse
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $deliveryBoy = $user?->deliveryBoy;

        if (!$deliveryBoy) {
            return ApiResponseType::sendJsonResponse(
                success: false,
                message: __('labels.not_a_delivery_boy'),
                data: [],
                status: 404
            );
        }

        $result = $this->walletService->getWallet($user->id, WalletTypeEnum::DELIVERY_BOY);
        if (!$result['success']) {
            return ApiResponseType::sendJsonResponse(
                success: false,
                message: $result['message'],
                data: $result['data'] ?? []
            );
        }

        return ApiResponseType::sendJsonResponse(
            success: true,
            message: $result['message'] ?? __('labels.wallet_retrieved_successfully'),
            data: new WalletResource($result['data'])
        );
    }

    /**
     * List delivery boy wallet transactions (paginated).
     *
     * @return JsonResponse
     */
    #[QueryParameter('page', description: 'Page number for pagination.', type: 'int', default: 1, example: 1)]
    #[QueryParameter('per_page', description: 'Number of transactions per page.', type: 'int', default: 15, example: 15)]
    #[QueryParameter('query', description: 'Search term (matches description, reference, payment method, amount).', type: 'string', example: 'earning')]
    #[QueryParameter('transaction_type', description: 'Filter by transaction type (deposit, payment, refund, adjustment, delivery_boy_referral_bonus).', type: 'string', example: 'deposit')]
    #[QueryParameter('status', description: 'Filter by transaction status (pending, completed, failed, cancelled, refunded, partially_refunded).', type: 'string', example: 'completed')]
    #[QueryParameter('payment_method', description: 'Filter by payment method.', type: 'string', example: 'admin')]
    #[QueryParameter('min_amount', description: 'Minimum transaction amount.', type: 'number', example: 10)]
    #[QueryParameter('max_amount', description: 'Maximum transaction amount.', type: 'number', example: 500)]
    #[QueryParameter('sort', description: 'Sort column.', type: 'string', example: 'created_at')]
    #[QueryParameter('order', description: 'Sort direction (asc, desc).', type: 'string', example: 'desc')]
    public function transactions(Request $request): JsonResponse
    {
        $user = $request->user();
        $deliveryBoy = $user?->deliveryBoy;

        if (!$deliveryBoy) {
            return ApiResponseType::sendJsonResponse(
                success: false,
                message: __('labels.not_a_delivery_boy'),
                data: [],
                status: 404
            );
        }

        $filters = $request->only([
            'query',
            'transaction_type',
            'status',
            'payment_method',
            'min_amount',
            'max_amount',
            'sort',
            'order',
            'per_page',
        ]);

        $result = $this->walletService->getTransactions($user->id, $filters, WalletTypeEnum::DELIVERY_BOY);
        if (!$result['success']) {
            return ApiResponseType::sendJsonResponse(
                success: false,
                message: $result['message'],
                data: $result['data'] ?? []
            );
        }

        $transactions = $result['data'];
        $transactions->getCollection()->transform(function ($transaction) {
            return new WalletTransactionResource($transaction);
        });

        return ApiResponseType::sendJsonResponse(
            success: true,
            message: $result['message'] ?? __('labels.wallet_transactions_retrieved_successfully'),
            data: [
                'current_page' => $transactions->currentPage(),
                'last_page'    => $transactions->lastPage(),
                'per_page'     => $transactions->perPage(),
                'total'        => $transactions->total(),
                'data'         => $transactions->items(),
            ]
        );
    }

    /**
     * Get single wallet transaction details.
     *
     * @param int $id Transaction id.
     * @return JsonResponse
     */
    public function transaction(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $deliveryBoy = $user?->deliveryBoy;

        if (!$deliveryBoy) {
            return ApiResponseType::sendJsonResponse(
                success: false,
                message: __('labels.not_a_delivery_boy'),
                data: [],
                status: 404
            );
        }

        $result = $this->walletService->getTransaction($user->id, $id, WalletTypeEnum::DELIVERY_BOY);
        if (!$result['success']) {
            return ApiResponseType::sendJsonResponse(
                success: false,
                message: $result['message'],
                data: $result['data'] ?? []
            );
        }

        return ApiResponseType::sendJsonResponse(
            success: true,
            message: $result['message'] ?? __('labels.wallet_transaction_retrieved_successfully'),
            data: new WalletTransactionResource($result['data'])
        );
    }
}
