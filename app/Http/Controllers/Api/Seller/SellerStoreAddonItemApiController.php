<?php

namespace App\Http\Controllers\Api\Seller;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAddonItem\BulkStoreAddonItemsRequest;
use App\Http\Requests\StoreAddonItem\StoreUpdateStoreAddonItemRequest;
use App\Http\Resources\StoreAddonItemResource;
use App\Models\AddonGroup;
use App\Models\AddonItem;
use App\Models\Store;
use App\Models\StoreAddonItem;
use App\Services\StoreAddonItemService;
use App\Types\Api\ApiResponseType;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Throwable;

#[Group('Seller Addon Inventory')]
class SellerStoreAddonItemApiController extends Controller
{
    use AuthorizesRequests;

    public function __construct(protected StoreAddonItemService $service)
    {
    }

    /**
     * List store addon inventory rows for the authenticated seller, filtered by addon group and/or store.
     *
     * @return JsonResponse
     */
    #[QueryParameter('page', description: 'Page number for pagination.', type: 'int', default: 1, example: 1)]
    #[QueryParameter('per_page', description: 'Items per page.', type: 'int', default: 15, example: 15)]
    #[QueryParameter('addon_group_id', description: 'Filter rows to items inside this addon group.', type: 'int', example: 42)]
    #[QueryParameter('store_id', description: 'Filter rows to a specific store the seller owns.', type: 'int', example: 7)]
    #[QueryParameter('search', description: 'Search by addon item title.', type: 'string', example: 'cheese')]
    public function index(Request $request): JsonResponse
    {
        try {
            $this->authorize('viewAny', StoreAddonItem::class);

            $user = auth()->user();
            $seller = $user?->seller();
            if (! $seller) {
                return ApiResponseType::sendJsonResponse(false, 'labels.seller_not_found', null, 404);
            }

            $perPage = (int) $request->input('per_page', 15);
            $groupId = $request->integer('addon_group_id') ?: null;
            $storeId = $request->integer('store_id') ?: null;
            $search  = trim((string) $request->input('search', ''));

            $query = $this->service
                ->queryForSellerByGroup($seller, $groupId, $storeId)
                ->with(['store:id,name,seller_id', 'addonItem:id,title,addon_group_id', 'addonItem.group:id,title,seller_id']);

            if ($search !== '') {
                $query->whereHas('addonItem', fn ($q) => $q->where('title', 'like', "%{$search}%"));
            }

            $paginator = $query->orderByDesc('id')->paginate($perPage);
            $paginator->getCollection()->transform(fn ($row) => new StoreAddonItemResource($row));

            return ApiResponseType::sendJsonResponse(
                true,
                'labels.store_addon_items_retrieved_successfully',
                ApiResponseType::responseFromPaginator($paginator),
            );
        } catch (AuthorizationException) {
            return ApiResponseType::sendJsonResponse(false, 'labels.permission_denied', [], 403);
        } catch (Throwable $e) {
            Log::error('Seller store addon items index error', ['error' => $e->getMessage()]);
            return ApiResponseType::sendJsonResponse(false, 'labels.failed_to_fetch_store_addon_items', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Show a single store addon inventory row owned by the authenticated seller.
     *
     * @param int $id Store addon item ID.
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        try {
            $user = auth()->user();
            $seller = $user?->seller();
            if (! $seller) {
                return ApiResponseType::sendJsonResponse(false, 'labels.seller_not_found', null, 404);
            }

            $row = $this->service
                ->queryForSeller($seller)
                ->with(['store:id,name,seller_id', 'addonItem:id,title,addon_group_id', 'addonItem.group:id,title,seller_id'])
                ->findOrFail($id);

            $this->authorize('view', $row);

            return ApiResponseType::sendJsonResponse(
                true,
                'labels.store_addon_item_retrieved_successfully',
                new StoreAddonItemResource($row),
            );
        } catch (ModelNotFoundException) {
            return ApiResponseType::sendJsonResponse(false, 'labels.store_addon_item_not_found', [], 404);
        } catch (AuthorizationException) {
            return ApiResponseType::sendJsonResponse(false, 'labels.permission_denied', [], 403);
        }
    }

    /**
     * Bulk-create store addon inventory rows across one or many stores in a single submission.
     *
     * Payload: { store_ids: [int, ...], items: [{addon_item_id, price, cost?, stock?, is_available?}, ...] }
     *
     * Every (store × item) pair is upserted. Pairs whose store or addon item
     * isn't owned by the authenticated seller are silently skipped and reported
     * back in `skipped_items` with a machine-readable `reason` so the caller
     * can show the seller exactly what landed and what was rejected.
     *
     * @return JsonResponse
     *
     * @response array{
     *     success: bool,
     *     message: string,
     *     data: array{
     *         saved_count: int,
     *         skipped_count: int,
     *         saved_items: array<int, array{id:int, store_id:int, addon_item_id:int, price:float, cost:?float, stock:int, is_available:bool}>,
     *         skipped_items: array<int, array{store_id:int, addon_item_id:?int, reason:string}>
     *     }
     * }
     */
    public function bulkStore(BulkStoreAddonItemsRequest $request): JsonResponse
    {
        try {
            $this->authorize('create', StoreAddonItem::class);

            $user = auth()->user();
            $seller = $user?->seller();
            if (! $seller) {
                return ApiResponseType::sendJsonResponse(false, 'labels.seller_not_found', null, 404);
            }

            $data = $request->validated();

            $result = $this->service->bulkUpsertAcrossStores(
                $seller,
                $data['store_ids'],
                $data['items'],
            );

            return ApiResponseType::sendJsonResponse(
                true,
                'labels.store_addon_items_saved_successfully',
                [
                    'saved_count'   => $result['saved'],
                    'skipped_count' => $result['skipped'],
                    'saved_items'   => StoreAddonItemResource::collection($result['saved_rows']),
                    'skipped_items' => $result['skipped_rows'],
                ],
                201,
            );
        } catch (ModelNotFoundException) {
            return ApiResponseType::sendJsonResponse(false, 'labels.store_addon_item_not_found', [], 404);
        } catch (AuthorizationException) {
            return ApiResponseType::sendJsonResponse(false, 'labels.permission_denied', [], 403);
        } catch (Throwable $e) {
            Log::error('Seller store addon item bulkStore error', ['error' => $e->getMessage()]);
            return ApiResponseType::sendJsonResponse(false, 'labels.failed_to_save_store_addon_item', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Update an existing store addon inventory row.
     *
     * @param int $id Store addon item ID.
     * @return JsonResponse
     */
    public function update(StoreUpdateStoreAddonItemRequest $request, int $id): JsonResponse
    {
        try {
            $user = auth()->user();
            $seller = $user?->seller();
            if (! $seller) {
                return ApiResponseType::sendJsonResponse(false, 'labels.seller_not_found', null, 404);
            }

            $row = $this->service
                ->queryForSeller($seller)
                ->with(['store', 'addonItem.group'])
                ->findOrFail($id);

            $this->authorize('update', $row);

            $data = $request->validated();

            $store = Store::query()
                ->where('id', $data['store_id'])
                ->where('seller_id', $seller->id)
                ->firstOrFail();

            $item = AddonItem::query()
                ->whereHas('group', fn ($q) => $q->where('seller_id', $seller->id))
                ->findOrFail($data['addon_item_id']);

            if ((int) $row->store_id !== (int) $store->id
                || (int) $row->addon_item_id !== (int) $item->id) {
                $this->service->delete($row);
            }

            $updated = $this->service->upsert($store, $item, [
                'price'        => $data['price'],
                'cost'         => $data['cost'] ?? null,
                'stock'        => $data['stock'] ?? 0,
                'is_available' => $data['is_available'] ?? false,
            ]);

            return ApiResponseType::sendJsonResponse(
                true,
                'labels.store_addon_item_updated_successfully',
                new StoreAddonItemResource($updated->fresh(['store', 'addonItem.group'])),
            );
        } catch (ModelNotFoundException) {
            return ApiResponseType::sendJsonResponse(false, 'labels.store_addon_item_not_found', [], 404);
        } catch (AuthorizationException) {
            return ApiResponseType::sendJsonResponse(false, 'labels.permission_denied', [], 403);
        } catch (Throwable $e) {
            Log::error('Seller store addon item update error', ['error' => $e->getMessage()]);
            return ApiResponseType::sendJsonResponse(false, 'labels.failed_to_update_store_addon_item', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Soft-delete a store addon inventory row.
     *
     * @param int $id Store addon item ID.
     * @return JsonResponse
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $user = auth()->user();
            $seller = $user?->seller();
            if (! $seller) {
                return ApiResponseType::sendJsonResponse(false, 'labels.seller_not_found', null, 404);
            }

            $row = $this->service->queryForSeller($seller)->findOrFail($id);
            $this->authorize('delete', $row);

            $this->service->delete($row);

            return ApiResponseType::sendJsonResponse(
                true,
                'labels.store_addon_item_deleted_successfully',
                [],
            );
        } catch (ModelNotFoundException) {
            return ApiResponseType::sendJsonResponse(false, 'labels.store_addon_item_not_found', [], 404);
        } catch (AuthorizationException) {
            return ApiResponseType::sendJsonResponse(false, 'labels.permission_denied', [], 403);
        } catch (Throwable $e) {
            Log::error('Seller store addon item delete error', ['error' => $e->getMessage()]);
            return ApiResponseType::sendJsonResponse(false, 'labels.failed_to_delete_store_addon_item', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Lookup: stores owned by the authenticated seller (id + name).
     *
     * @return JsonResponse
     */
    public function stores(): JsonResponse
    {
        $user = auth()->user();
        $seller = $user?->seller();
        if (! $seller) {
            return ApiResponseType::sendJsonResponse(false, 'labels.seller_not_found', null, 404);
        }

        $stores = Store::query()
            ->where('seller_id', $seller->id)
            ->orderBy('name')
            ->get(['id', 'name'])->select('id', 'name');

        return ApiResponseType::sendJsonResponse(
            true,
            'labels.stores_retrieved_successfully',
            $stores,
        );
    }

    /**
     * Lookup: addon groups belonging to the authenticated seller (id + title).
     *
     * @return JsonResponse
     */
    public function addonGroups(): JsonResponse
    {
        $user = auth()->user();
        $seller = $user?->seller();
        if (! $seller) {
            return ApiResponseType::sendJsonResponse(false, 'labels.seller_not_found', null, 404);
        }

        $groups = AddonGroup::query()
            ->where('seller_id', $seller->id)
            ->orderBy('title')
            ->get(['id', 'title']);

        return ApiResponseType::sendJsonResponse(
            true,
            'labels.addon_groups_retrieved_successfully',
            $groups,
        );
    }

    /**
     * Lookup: current inventory row for a (store, addon_item) pair.
     *
     * Used by clients (mobile + web panel bulk form) to prefill price / cost /
     * stock / is_available when the seller picks an addon item that's already
     * stocked at the chosen store. Returns { exists: false } when no row is
     * stocked yet so the client can fall back to catalog defaults.
     *
     * @return JsonResponse
     */
    #[QueryParameter('store_id', description: 'Store ID owned by the authenticated seller.', type: 'int', required: true, example: 7)]
    #[QueryParameter('addon_item_id', description: 'Addon item ID belonging to a group owned by the authenticated seller.', type: 'int', required: true, example: 42)]
    public function state(Request $request): JsonResponse
    {
        try {
            $this->authorize('viewAny', StoreAddonItem::class);

            $user = auth()->user();
            $seller = $user?->seller();
            if (! $seller) {
                return ApiResponseType::sendJsonResponse(false, 'labels.seller_not_found', null, 404);
            }

            $data = $request->validate([
                'store_id' => [
                    'required',
                    'integer',
                    Rule::exists('stores', 'id')->where(fn ($q) => $q->where('seller_id', $seller->id)),
                ],
                'addon_item_id' => [
                    'required',
                    'integer',
                    Rule::exists('addon_items', 'id')->where(function ($q) use ($seller) {
                        $q->whereIn('addon_group_id', function ($sub) use ($seller) {
                            $sub->select('id')->from('addon_groups')->where('seller_id', $seller->id);
                        });
                    }),
                ],
            ]);

            $row = $this->service->findForSeller($seller, (int) $data['store_id'], (int) $data['addon_item_id']);

            if (! $row) {
                return ApiResponseType::sendJsonResponse(
                    true,
                    'labels.store_addon_item_not_found',
                    ['exists' => false],
                );
            }

            return ApiResponseType::sendJsonResponse(
                true,
                'labels.store_addon_item_retrieved_successfully',
                [
                    'exists'       => true,
                    'id'           => $row->id,
                    'price'        => (float) $row->price,
                    'cost'         => $row->cost !== null ? (float) $row->cost : null,
                    'stock'        => (int) $row->stock,
                    'is_available' => (bool) $row->is_available,
                ],
            );
        } catch (AuthorizationException) {
            return ApiResponseType::sendJsonResponse(false, 'labels.permission_denied', [], 403);
        }
    }

    /**
     * Lookup: addon items belonging to a specific group owned by the seller.
     *
     * @param int $groupId Addon group ID.
     * @return JsonResponse
     */
    public function itemsForGroup(int $groupId): JsonResponse
    {
        $user = auth()->user();
        $seller = $user?->seller();
        if (! $seller) {
            return ApiResponseType::sendJsonResponse(false, 'labels.seller_not_found', null, 404);
        }

        $group = AddonGroup::query()
            ->where('id', $groupId)
            ->where('seller_id', $seller->id)
            ->first();

        if (! $group) {
            return ApiResponseType::sendJsonResponse(false, 'labels.addon_group_not_found', [], 404);
        }

        $items = AddonItem::query()
            ->where('addon_group_id', $group->id)
            ->orderBy('title')
            ->get(['id', 'title', 'addon_group_id', 'price', 'cost']);

        return ApiResponseType::sendJsonResponse(
            true,
            'labels.addon_items_retrieved_successfully',
            $items,
        );
    }
}
