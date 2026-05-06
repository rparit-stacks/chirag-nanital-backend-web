<?php

namespace App\Http\Controllers\Api\Seller;

use App\Enums\Addon\AddonGroupSelectionTypeEnum;
use App\Enums\Addon\AddonGroupStatusEnum;
use App\Enums\Addon\AddonItemIndicatorEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\AddonGroup\StoreUpdateAddonGroupRequest;
use App\Http\Resources\AddonGroupResource;
use App\Models\AddonGroup;
use App\Services\AddonGroupService;
use App\Types\Api\ApiResponseType;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Group('Seller Addon Groups')]
class SellerAddonGroupApiController extends Controller
{
    use AuthorizesRequests;

    public function __construct(protected AddonGroupService $addonGroupService)
    {
    }

    /**
     * List addon groups for the authenticated seller with pagination and filters.
     *
     * @return JsonResponse
     */
    #[QueryParameter('page', description: 'Page number for pagination.', type: 'int', default: 1, example: 1)]
    #[QueryParameter('per_page', description: 'Items per page.', type: 'int', default: 15, example: 15)]
    #[QueryParameter('search', description: 'Search by group title.', type: 'string', example: 'Toppings')]
    #[QueryParameter('status', description: 'Filter by group status.', type: 'string', example: 'active')]
    #[QueryParameter('selection_type', description: 'Filter by selection type.', type: 'string', example: 'single, multiple')]
    #[QueryParameter('is_required', description: 'Filter required/optional groups.', type: 'boolean', example: true)]
    public function index(Request $request): JsonResponse
    {
        try {
            $this->authorize('viewAny', AddonGroup::class);

            $user = auth()->user();
            $seller = $user?->seller();
            if (!$seller) {
                return ApiResponseType::sendJsonResponse(false, 'labels.seller_not_found', null, 404);
            }

            $perPage = (int) $request->input('per_page', 15);
            $search  = trim((string) $request->input('search', ''));
            $status  = $request->input('status');
            $type    = $request->input('selection_type');
            $required = $request->input('is_required');

            $query = AddonGroup::query()
                ->where('seller_id', $seller->id)
                ->with('items')
                ->withCount('items');

            if ($search !== '') {
                $query->where('title', 'like', "%{$search}%");
            }
            if ($status !== null && $status !== '') {
                $query->where('status', $status);
            }
            if ($type !== null && $type !== '') {
                $query->where('selection_type', $type);
            }
            if ($required !== null && $required !== '') {
                $query->where('is_required', filter_var($required, FILTER_VALIDATE_BOOLEAN));
            }

            $paginator = $query->orderByDesc('id')->paginate($perPage);
            $paginator->getCollection()->transform(fn ($group) => new AddonGroupResource($group));

            return ApiResponseType::sendJsonResponse(
                true,
                'labels.addon_groups_fetched_successfully',
                ApiResponseType::responseFromPaginator($paginator),
            );
        } catch (AuthorizationException) {
            return ApiResponseType::sendJsonResponse(false, 'labels.permission_denied', [], 403);
        } catch (Throwable $e) {
            Log::error('Seller addon groups index error', ['error' => $e->getMessage()]);
            return ApiResponseType::sendJsonResponse(false, 'labels.failed_to_fetch_addon_groups', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Enum options for building the addon group form on clients (statuses, selection types, indicators).
     *
     * @return JsonResponse
     */
    public function enums(): JsonResponse
    {
        return ApiResponseType::sendJsonResponse(
            true,
            'labels.addon_group_enums_fetched_successfully',
            [
                'statuses' => collect(AddonGroupStatusEnum::cases())
                    ->map(fn ($c) => ['value' => $c->value, 'label' => ucfirst($c->value)])->values(),
                'selection_types' => collect(AddonGroupSelectionTypeEnum::cases())
                    ->map(fn ($c) => ['value' => $c->value, 'label' => ucfirst($c->value)])->values(),
                'indicators' => collect(AddonItemIndicatorEnum::cases())
                    ->map(fn ($c) => ['value' => $c->value, 'label' => ucfirst(str_replace('_', ' ', $c->value))])->values(),
            ],
        );
    }

    /**
     * Show a single addon group owned by the authenticated seller.
     *
     * @param int $id Addon group ID.
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        try {
            $user = auth()->user();
            $seller = $user?->seller();
            if (!$seller) {
                return ApiResponseType::sendJsonResponse(false, 'labels.seller_not_found', null, 404);
            }

            $group = AddonGroup::with('items')
                ->where('seller_id', $seller->id)
                ->findOrFail($id);

            $this->authorize('view', $group);

            return ApiResponseType::sendJsonResponse(
                true,
                'labels.addon_group_fetched_successfully',
                new AddonGroupResource($group),
            );
        } catch (ModelNotFoundException) {
            return ApiResponseType::sendJsonResponse(false, 'labels.addon_group_not_found', [], 404);
        } catch (AuthorizationException) {
            return ApiResponseType::sendJsonResponse(false, 'labels.permission_denied', [], 403);
        }
    }

    /**
     * Create a new addon group together with its items for the authenticated seller.
     *
     * @return JsonResponse
     */
    public function store(StoreUpdateAddonGroupRequest $request): JsonResponse
    {
        try {
            $this->authorize('create', AddonGroup::class);

            $user = auth()->user();
            $seller = $user?->seller();
            if (!$seller) {
                return ApiResponseType::sendJsonResponse(false, 'labels.seller_not_found', null, 404);
            }

            $group = $this->addonGroupService->createWithItems($seller, $request->validated());

            return ApiResponseType::sendJsonResponse(
                true,
                'labels.addon_group_created_successfully',
                new AddonGroupResource($group),
                201,
            );
        } catch (AuthorizationException) {
            return ApiResponseType::sendJsonResponse(false, 'labels.permission_denied', [], 403);
        } catch (Throwable $e) {
            Log::error('Seller addon group store error', ['error' => $e->getMessage()]);
            return ApiResponseType::sendJsonResponse(false, 'labels.failed_to_save_addon_group', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Update an existing addon group together with its items.
     *
     * @param int $id Addon group ID.
     * @return JsonResponse
     */
    public function update(StoreUpdateAddonGroupRequest $request, int $id): JsonResponse
    {
        try {
            $user = auth()->user();
            $seller = $user?->seller();
            if (!$seller) {
                return ApiResponseType::sendJsonResponse(false, 'labels.seller_not_found', null, 404);
            }

            $group = AddonGroup::with('items')
                ->where('seller_id', $seller->id)
                ->findOrFail($id);

            $this->authorize('update', $group);

            $updated = $this->addonGroupService->updateWithItems($group, $request->validated());

            return ApiResponseType::sendJsonResponse(
                true,
                'labels.addon_group_updated_successfully',
                new AddonGroupResource($updated),
            );
        } catch (ModelNotFoundException) {
            return ApiResponseType::sendJsonResponse(false, 'labels.addon_group_not_found', [], 404);
        } catch (AuthorizationException) {
            return ApiResponseType::sendJsonResponse(false, 'labels.permission_denied', [], 403);
        } catch (Throwable $e) {
            Log::error('Seller addon group update error', ['error' => $e->getMessage()]);
            return ApiResponseType::sendJsonResponse(false, 'labels.failed_to_update_addon_group', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Soft-delete an addon group together with its items.
     *
     * @param int $id Addon group ID.
     * @return JsonResponse
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $user = auth()->user();
            $seller = $user?->seller();
            if (!$seller) {
                return ApiResponseType::sendJsonResponse(false, 'labels.seller_not_found', null, 404);
            }

            $group = AddonGroup::where('seller_id', $seller->id)->findOrFail($id);
            $this->authorize('delete', $group);

            $this->addonGroupService->deleteGroup($group);

            return ApiResponseType::sendJsonResponse(true, 'labels.addon_group_deleted_successfully', []);
        } catch (ModelNotFoundException) {
            return ApiResponseType::sendJsonResponse(false, 'labels.addon_group_not_found', [], 404);
        } catch (AuthorizationException) {
            return ApiResponseType::sendJsonResponse(false, 'labels.permission_denied', [], 403);
        } catch (Throwable $e) {
            Log::error('Seller addon group delete error', ['error' => $e->getMessage()]);
            return ApiResponseType::sendJsonResponse(false, 'labels.failed_to_delete_addon_group', ['error' => $e->getMessage()], 500);
        }
    }
}
