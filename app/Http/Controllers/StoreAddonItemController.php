<?php

namespace App\Http\Controllers;

use App\Enums\DefaultSystemRolesEnum;
use App\Enums\SellerPermissionEnum;
use App\Http\Requests\StoreAddonItem\BulkStoreAddonItemsRequest;
use App\Http\Requests\StoreAddonItem\StoreUpdateStoreAddonItemRequest;
use App\Models\AddonGroup;
use App\Models\AddonItem;
use App\Models\Store;
use App\Models\StoreAddonItem;
use App\Services\StoreAddonItemService;
use App\Traits\ChecksPermissions;
use App\Traits\PanelAware;
use App\Types\Api\ApiResponseType;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Seller panel: manage store-level addon inventory (price / cost / stock / availability)
 * for the rows in store_addon_items. One controller in the shared namespace so it can
 * be wired later from the admin panel if needed — today only the seller routes reach it.
 */
class StoreAddonItemController extends Controller
{
    use ChecksPermissions, PanelAware, AuthorizesRequests;

    protected bool $viewPermission = false;
    protected bool $createPermission = false;
    protected bool $editPermission = false;
    protected bool $deletePermission = false;
    public $sellerId;

    public function __construct(protected StoreAddonItemService $service)
    {
        $user = auth()->user();
        $seller = $user?->seller();
        $this->sellerId = $seller ? $seller->id : 0;

        if ($this->getPanel() === 'seller' && $user) {
            $isOwnerSeller = $user->hasRole(DefaultSystemRolesEnum::SELLER());
            $this->viewPermission   = $isOwnerSeller || $this->hasPermission(SellerPermissionEnum::STORE_ADDON_ITEM_VIEW());
            $this->createPermission = $isOwnerSeller || $this->hasPermission(SellerPermissionEnum::STORE_ADDON_ITEM_CREATE());
            $this->editPermission   = $isOwnerSeller || $this->hasPermission(SellerPermissionEnum::STORE_ADDON_ITEM_EDIT());
            $this->deletePermission = $isOwnerSeller || $this->hasPermission(SellerPermissionEnum::STORE_ADDON_ITEM_DELETE());
        }
    }

    /**
     * Render the dedicated full-page "Add addon items to stores" form.
     *
     * The modal variant was retired because a multi-store × multi-item grid
     * doesn't fit comfortably inside a Bootstrap modal — sellers typically
     * have several stores and want to review every row before saving.
     */
    public function create(): View
    {
        $this->authorize('create', StoreAddonItem::class);
        $seller = $this->ensureSeller();

        $addonGroups = AddonGroup::query()
            ->where('seller_id', $seller->id)
            ->orderBy('title')
            ->get(['id', 'title']);

        $stores = Store::query()
            ->where('seller_id', $seller->id)
            ->orderBy('name')
            ->get(['id', 'name']);

        return view($this->panelView('store_addon_items.form'), [
            'addonGroups'      => $addonGroups,
            'stores'           => $stores,
            'createPermission' => $this->createPermission,
            'editPermission'   => $this->editPermission,
        ]);
    }

    /**
     * Display the inventory listing + single-row edit modal shell.
     */
    public function index(): View
    {
        $this->authorize('viewAny', StoreAddonItem::class);
        $seller = $this->ensureSeller();

        $addonGroups = AddonGroup::query()
            ->where('seller_id', $seller->id)
            ->orderBy('title')
            ->get(['id', 'title']);

        $stores = Store::query()
            ->where('seller_id', $seller->id)
            ->orderBy('name')
            ->get(['id', 'name']);

        $columns = [
            ['data' => 'id',              'name' => 'id',              'title' => __('labels.id')],
            ['data' => 'store_name',      'name' => 'store_name',      'title' => __('labels.store')],
            ['data' => 'addon_item_title','name' => 'addon_item_title','title' => __('labels.addon_item')],
            ['data' => 'price',           'name' => 'price',           'title' => __('labels.price')],
            ['data' => 'cost',            'name' => 'cost',            'title' => __('labels.cost')],
            ['data' => 'stock',           'name' => 'stock',           'title' => __('labels.stock')],
            ['data' => 'is_available',    'name' => 'is_available',    'title' => __('labels.is_available')],
            ['data' => 'action',          'name' => 'action',          'title' => __('labels.action'), 'orderable' => false, 'searchable' => false],
        ];

        $viewPermission   = $this->viewPermission;
        $createPermission = $this->createPermission;
        $editPermission   = $this->editPermission;
        $deletePermission = $this->deletePermission;

        return view($this->panelView('store_addon_items.index'), compact(
            'columns',
            'addonGroups',
            'stores',
            'viewPermission',
            'createPermission',
            'editPermission',
            'deletePermission',
        ));
    }

    /**
     * DataTable endpoint consumed by the Store Addon Inventory listing.
     */
    public function datatable(Request $request): JsonResponse
    {
        $this->authorize('viewAny', StoreAddonItem::class);
        $seller = $this->ensureSeller();

        $draw   = (int) $request->get('draw');
        $start  = (int) $request->get('start', 0);
        $length = (int) $request->get('length', 15);
        $search = $request->get('search')['value'] ?? '';

        $groupId = $request->integer('addon_group_id') ?: null;
        $storeId = $request->integer('store_id') ?: null;

        $base = $this->service
            ->queryForSellerByGroup($seller, $groupId, $storeId)
            ->with(['store:id,name', 'addonItem:id,title,addon_group_id', 'addonItem.group:id,title']);

        $total = (clone $base)->count();

        if ($search !== '') {
            $base->whereHas('addonItem', fn ($q) => $q->where('title', 'like', "%{$search}%"));
        }

        $filtered = (clone $base)->count();

        $orderColumns = ['id', 'store_name', 'addon_item_title', 'price', 'cost', 'stock', 'is_available'];
        $orderIndex   = (int) ($request->get('order')[0]['column'] ?? 0);
        $orderDir     = $request->get('order')[0]['dir'] ?? 'desc';
        $orderColumn  = $orderColumns[$orderIndex] ?? 'id';
        // store_name / addon_item_title are virtual; fall back to id for SQL ordering.
        $sqlOrder     = in_array($orderColumn, ['id', 'price', 'cost', 'stock', 'is_available'], true)
            ? $orderColumn
            : 'id';

        $rows = $base
            ->orderBy($sqlOrder, $orderDir)
            ->skip($start)
            ->take($length)
            ->get()
            ->map(function (StoreAddonItem $row) {
                return [
                    'id'                => $row->id,
                    'store_name'        => e($row->store?->name),
                    'addon_item_title'  => e($row->addonItem?->title) . ' <small class="text-muted">' . e($row->addonItem?->group?->title) . '</small>',
                    'price'             => number_format((float) $row->price, 2),
                    'cost'              => $row->cost !== null ? number_format((float) $row->cost, 2) : '—',
                    'stock'             => (int) $row->stock,
                    'is_available'      => view('partials.status', ['status' => $row->is_available ? 'active' : 'inactive'])->render(),
                    'action'            => view('partials.actions', [
                        'modelName'         => 'store-addon-item',
                        'id'                => $row->id,
                        'title'             => $row->addonItem?->title,
                        'mode'              => 'model_view',
                        'editPermission'    => $this->editPermission,
                        'deletePermission'  => $this->deletePermission,
                    ])->render(),
                ];
            })
            ->toArray();

        return response()->json([
            'draw'            => $draw,
            'recordsTotal'    => $total,
            'recordsFiltered' => $filtered,
            'data'            => $rows,
        ]);
    }

    /**
     * Return a single row for the edit modal.
     */
    public function show($id): JsonResponse
    {
        try {
            $row = StoreAddonItem::with(['store:id,name,seller_id', 'addonItem:id,title,addon_group_id', 'addonItem.group:id,title,seller_id'])
                ->findOrFail($id);
            $this->authorize('view', $row);

            return ApiResponseType::sendJsonResponse(
                success: true,
                message: 'labels.store_addon_item_retrieved_successfully',
                data: [
                    'id'                 => $row->id,
                    'store_id'           => $row->store_id,
                    'store_name'         => $row->store?->name,
                    'addon_item_id'      => $row->addon_item_id,
                    'addon_item_title'   => $row->addonItem?->title,
                    'addon_group_id'     => $row->addonItem?->addon_group_id,
                    'addon_group_title'  => $row->addonItem?->group?->title,
                    'price'              => (float) $row->price,
                    'cost'               => $row->cost !== null ? (float) $row->cost : null,
                    'stock'              => (int) $row->stock,
                    'is_available'       => (bool) $row->is_available,
                ],
            );
        } catch (ModelNotFoundException) {
            return ApiResponseType::sendJsonResponse(
                success: false,
                message: 'labels.store_addon_item_not_found',
                data: [],
                status: 404,
            );
        } catch (AuthorizationException) {
            return ApiResponseType::sendJsonResponse(
                success: false,
                message: 'labels.permission_denied',
                data: [],
                status: 403,
            );
        }
    }

    /**
     * Create a new store addon item row.
     */
    public function store(StoreUpdateStoreAddonItemRequest $request): JsonResponse
    {
        try {
            $this->authorize('create', StoreAddonItem::class);
            $seller = $this->ensureSeller();
            $data = $request->validated();

            $store = Store::query()
                ->where('id', $data['store_id'])
                ->where('seller_id', $seller->id)
                ->firstOrFail();

            $item = AddonItem::query()
                ->whereHas('group', fn ($q) => $q->where('seller_id', $seller->id))
                ->findOrFail($data['addon_item_id']);

            $row = $this->service->upsert($store, $item, [
                'price'        => $data['price'],
                'cost'         => $data['cost'] ?? null,
                'stock'        => $data['stock'] ?? 0,
                'is_available' => $data['is_available'] ?? false,
            ]);

            return ApiResponseType::sendJsonResponse(
                success: true,
                message: 'labels.store_addon_item_saved_successfully',
                data: ['id' => $row->id],
                status: 201,
            );
        } catch (ModelNotFoundException) {
            return ApiResponseType::sendJsonResponse(false, 'labels.store_addon_item_not_found', [], 404);
        } catch (AuthorizationException) {
            return ApiResponseType::sendJsonResponse(false, 'labels.permission_denied', [], 403);
        } catch (\Throwable $e) {
            Log::error('StoreAddonItemController@store failed', ['error' => $e->getMessage()]);
            return ApiResponseType::sendJsonResponse(false, 'labels.failed_to_save_store_addon_item', []);
        }
    }

    /**
     * Update an existing store addon item row.
     */
    public function update(StoreUpdateStoreAddonItemRequest $request, $id): JsonResponse
    {
        try {
            $row = StoreAddonItem::with(['store', 'addonItem.group'])->findOrFail($id);
            $this->authorize('update', $row);
            $seller = $this->ensureSeller();

            $data = $request->validated();

            $store = Store::query()
                ->where('id', $data['store_id'])
                ->where('seller_id', $seller->id)
                ->firstOrFail();

            $item = AddonItem::query()
                ->whereHas('group', fn ($q) => $q->where('seller_id', $seller->id))
                ->findOrFail($data['addon_item_id']);

            // If the caller changed store_id or addon_item_id, we soft-delete the
            // current row and upsert a new pairing. Otherwise upsert keeps the row.
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
                success: true,
                message: 'labels.store_addon_item_updated_successfully',
                data: ['id' => $updated->id],
            );
        } catch (ModelNotFoundException) {
            return ApiResponseType::sendJsonResponse(false, 'labels.store_addon_item_not_found', [], 404);
        } catch (AuthorizationException) {
            return ApiResponseType::sendJsonResponse(false, 'labels.permission_denied', [], 403);
        } catch (\Throwable $e) {
            Log::error('StoreAddonItemController@update failed', ['error' => $e->getMessage()]);
            return ApiResponseType::sendJsonResponse(false, 'labels.failed_to_update_store_addon_item', []);
        }
    }

    /**
     * Bulk-create store_addon_items for one or many stores in a single submission.
     *
     * Payload: {
     *     store_ids: [int, ...],           // at least one — broadcast target
     *     items:     [{addon_item_id, price, cost?, stock?, is_available?}, ...],
     * }
     *
     * Every (store × item) pair is upserted. Sellers can refine per-store values
     * afterwards via the single-row edit modal.
     */
    public function bulkStore(BulkStoreAddonItemsRequest $request): JsonResponse
    {
        try {
            $this->authorize('create', StoreAddonItem::class);
            $seller = $this->ensureSeller();
            $data = $request->validated();

            $result = $this->service->bulkUpsertAcrossStores(
                $seller,
                $data['store_ids'],
                $data['items'],
            );

            return ApiResponseType::sendJsonResponse(
                success: true,
                message: 'labels.store_addon_items_saved_successfully',
                data: [
                    'saved'        => $result['saved'],
                    'skipped'      => $result['skipped'],
                    'skipped_rows' => $result['skipped_rows'],
                    'redirect_url' => route('seller.store-addon-items.index'),
                ],
                status: 201,
            );
        } catch (ModelNotFoundException) {
            return ApiResponseType::sendJsonResponse(false, 'labels.store_addon_item_not_found', [], 404);
        } catch (AuthorizationException) {
            return ApiResponseType::sendJsonResponse(false, 'labels.permission_denied', [], 403);
        } catch (\Throwable $e) {
            Log::error('StoreAddonItemController@bulkStore failed', ['error' => $e->getMessage()]);
            return ApiResponseType::sendJsonResponse(false, 'labels.failed_to_save_store_addon_item', []);
        }
    }

    /**
     * Soft-delete a store addon item row.
     */
    public function destroy($id): JsonResponse
    {
        try {
            $row = StoreAddonItem::with(['store', 'addonItem.group'])->findOrFail($id);
            $this->authorize('delete', $row);

            $this->service->delete($row);

            return ApiResponseType::sendJsonResponse(
                success: true,
                message: 'labels.store_addon_item_deleted_successfully',
                data: [],
            );
        } catch (ModelNotFoundException) {
            return ApiResponseType::sendJsonResponse(false, 'labels.store_addon_item_not_found', [], 404);
        } catch (AuthorizationException) {
            return ApiResponseType::sendJsonResponse(false, 'labels.permission_denied', [], 403);
        } catch (\Throwable $e) {
            Log::error('StoreAddonItemController@destroy failed', ['error' => $e->getMessage()]);
            return ApiResponseType::sendJsonResponse(false, 'labels.failed_to_delete_store_addon_item', []);
        }
    }

    /**
     * Lookup: current inventory row for a (store, addon_item) pair, if any.
     *
     * Powers the bulk "Add addon items to store" form — when the seller picks an
     * addon item that's already stocked at the chosen store we prefill the row's
     * price / cost / stock / is_available from the existing DB row. Returns
     * { exists: false } when nothing is stocked yet so the UI can fall back to
     * the addon item's catalog defaults.
     */
    public function inventoryState(Request $request): JsonResponse
    {
        $this->authorize('viewAny', StoreAddonItem::class);
        $seller = $this->ensureSeller();

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
                success: true,
                message: 'labels.store_addon_item_not_found',
                data: ['exists' => false],
            );
        }

        return ApiResponseType::sendJsonResponse(
            success: true,
            message: 'labels.store_addon_item_retrieved_successfully',
            data: [
                'exists'       => true,
                'id'           => $row->id,
                'price'        => (float) $row->price,
                'cost'         => $row->cost !== null ? (float) $row->cost : null,
                'stock'        => (int) $row->stock,
                'is_available' => (bool) $row->is_available,
            ],
        );
    }

    /**
     * Lookup: existing inventory snapshot for a (stores × addon_items) grid.
     *
     * Powers the full-page bulk form — when the seller picks a set of stores
     * and a set of addon items, we surface any rows that already exist so the
     * UI can flag those cells as "will update" rather than "will create" and
     * show the current price / stock.
     */
    public function inventoryStateMatrix(Request $request): JsonResponse
    {
        $this->authorize('viewAny', StoreAddonItem::class);
        $seller = $this->ensureSeller();

        $data = $request->validate([
            'store_ids'       => ['required', 'array', 'min:1'],
            'store_ids.*'     => ['integer', Rule::exists('stores', 'id')->where(fn ($q) => $q->where('seller_id', $seller->id))],
            'addon_item_ids'  => ['required', 'array', 'min:1'],
            'addon_item_ids.*' => [
                'integer',
                Rule::exists('addon_items', 'id')->where(function ($q) use ($seller) {
                    $q->whereIn('addon_group_id', function ($sub) use ($seller) {
                        $sub->select('id')->from('addon_groups')->where('seller_id', $seller->id);
                    });
                }),
            ],
        ]);

        $matrix = $this->service->inventoryMatrix(
            $seller,
            $data['store_ids'],
            $data['addon_item_ids'],
        );

        return ApiResponseType::sendJsonResponse(
            success: true,
            message: 'labels.store_addon_items_retrieved_successfully',
            data: ['matrix' => $matrix],
        );
    }

    /**
     * Lookup: addon items belonging to the selected group (scoped to the seller).
     */
    public function itemsForGroup($groupId): JsonResponse
    {
        $this->authorize('viewAny', StoreAddonItem::class);
        $seller = $this->ensureSeller();

        $group = AddonGroup::query()
            ->where('id', $groupId)
            ->where('seller_id', $seller->id)
            ->first();

        if (! $group) {
            return ApiResponseType::sendJsonResponse(true, 'labels.addon_group_not_found', []);
        }

        $items = AddonItem::query()
            ->where('addon_group_id', $group->id)
            ->orderBy('title')
            ->get(['id', 'title', 'addon_group_id', 'price', 'cost']);

        return ApiResponseType::sendJsonResponse(
            success: true,
            message: 'labels.addon_items_retrieved_successfully',
            data: $items,
        );
    }
}
