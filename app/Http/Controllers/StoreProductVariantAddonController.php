<?php

namespace App\Http\Controllers;

use App\Enums\DefaultSystemRolesEnum;
use App\Enums\Product\ProductTypeEnum;
use App\Enums\SellerPermissionEnum;
use App\Exceptions\SellerNotFoundException;
use App\Http\Requests\ProductAddon\AttachProductAddonRequest;
use App\Http\Requests\ProductAddon\BulkAttachProductAddonRequest;
use App\Models\AddonGroup;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\StoreProductVariantAddon;
use App\Services\ProductAddonAttachmentService;
use App\Traits\ChecksPermissions;
use App\Traits\PanelAware;
use App\Types\Api\ApiResponseType;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class StoreProductVariantAddonController extends Controller
{
    use ChecksPermissions, PanelAware, AuthorizesRequests;

    public int $sellerId = 0;
    protected bool $createPermission = false;
    protected bool $editPermission = false;
    protected bool $deletePermission = false;

    public function __construct(protected ProductAddonAttachmentService $attachmentService)
    {
        $user = auth()->user();
        $seller = $user?->seller();
        $this->sellerId = $seller ? (int) $seller->id : 0;

        if ($this->getPanel() === 'seller' && $user) {
            $isOwner = $user->hasRole(DefaultSystemRolesEnum::SELLER());
            $this->createPermission = $isOwner || $this->hasPermission(SellerPermissionEnum::PRODUCT_ADDON_CREATE());
            $this->editPermission   = $isOwner || $this->hasPermission(SellerPermissionEnum::PRODUCT_ADDON_EDIT());
            $this->deletePermission = $isOwner || $this->hasPermission(SellerPermissionEnum::PRODUCT_ADDON_DELETE());
        }
    }

    /**
     * Listing screen – shows one row per distinct (variant, group) attachment.
     */
    public function index(): View
    {
        $this->authorize('viewAny', StoreProductVariantAddon::class);

        $columns = [
            ['data' => 'product',      'name' => 'product',      'title' => __('labels.product')],
            ['data' => 'variant',      'name' => 'variant',      'title' => __('labels.variant')],
            ['data' => 'addon_group',  'name' => 'addon_group',  'title' => __('labels.addon_group')],
            ['data' => 'items_count',  'name' => 'items_count',  'title' => __('labels.addon_items_count'), 'orderable' => false, 'searchable' => false],
            ['data' => 'stores_count', 'name' => 'stores_count', 'title' => __('labels.stores'), 'orderable' => false, 'searchable' => false],
            ['data' => 'updated_at',   'name' => 'updated_at',   'title' => __('labels.updated_at')],
            ['data' => 'action',       'name' => 'action',       'title' => __('labels.action'), 'orderable' => false, 'searchable' => false],
        ];

        return view($this->panelView('product_addons.index'), [
            'columns'          => $columns,
            'createPermission' => $this->createPermission,
            'editPermission'   => $this->editPermission,
            'deletePermission' => $this->deletePermission,
        ]);
    }

    /**
     * Datatable feed for the listing screen (server-side).
     */
    public function datatable(Request $request): JsonResponse
    {
        $this->authorize('viewAny', StoreProductVariantAddon::class);
        $seller = $this->ensureSeller();

        $draw        = (int) $request->get('draw');
        $start       = (int) $request->get('start', 0);
        $length      = max(1, (int) $request->get('length', 10));
        $searchValue = (string) ($request->get('search')['value'] ?? '');

        $page = (int) floor($start / $length) + 1;

        $paginator = $this->attachmentService
            ->listAttachments($seller, $searchValue ?: null)
            ->paginate($length, ['*'], 'page', $page);

        $totalRecords = $paginator->total();

        $data = $paginator->getCollection()->map(function ($row) {
            $productTitle = (string) ($row->productVariant?->product?->title ?? '');
            $variantTitle = (string) ($row->productVariant?->title ?? '');
            $groupTitle   = (string) ($row->addonGroup?->title ?? '');

            return [
                'product'      => e($productTitle),
                'variant'      => '<span class="badge bg-primary-lt">' . e($variantTitle) . '</span>',
                'addon_group'  => e($groupTitle),
                'items_count'  => '<span class="badge bg-blue-lt">' . (int) $row->items_count . '</span>',
                'stores_count' => '<span class="badge bg-green-lt">' . (int) $row->stores_count . '</span>',
                'updated_at'   => $row->updated_at ? date('Y-m-d', strtotime($row->updated_at)) : '',
                'action'       => view('partials.actions', [
                    'modelName'        => 'product-addon',
                    'id'               => $row->product_variant_id . '-' . $row->addon_group_id,
                    'title'            => $productTitle . ' / ' . $groupTitle,
                    'mode'             => 'full_view',
                    'route'            => route('seller.product-addons.edit', [$row->product_variant_id, $row->addon_group_id]),
                    'editPermission'   => $this->editPermission,
                    'deletePermission' => $this->deletePermission,
                ])->render(),
            ];
        })->toArray();

        return response()->json([
            'draw'            => $draw,
            'recordsTotal'    => $totalRecords,
            'recordsFiltered' => $totalRecords,
            'data'            => $data,
        ]);
    }

    /**
     * Render the attach form (variant+group pickers + empty matrix).
     */
    public function create(): View
    {
        $this->authorize('create', StoreProductVariantAddon::class);

        return view($this->panelView('product_addons.form'), [
            'mode'             => 'create',
            'variant'          => null,
            'group'            => null,
            'stores'           => collect(),
            'items'            => collect(),
            'existing'         => collect(),
            'inventory'        => collect(),
            'editPermission'   => $this->editPermission,
        ]);
    }

    /**
     * Render the edit form prefilled with existing attachment rows.
     */
    public function edit(int $variantId, int $groupId): View
    {
        $seller  = $this->ensureSeller();
        $variant = $this->resolveOwnedVariant($variantId);
        $group   = $this->resolveOwnedGroup($groupId);

        $payload = $this->attachmentService->buildFormPayload($seller, $variant, $group);

        return view($this->panelView('product_addons.form'), array_merge($payload, [
            'mode'           => 'edit',
            'editPermission' => $this->editPermission,
        ]));
    }

    /**
     * Persist one or many (variant × group) attachments in a single bulk submission.
     */
    public function store(BulkAttachProductAddonRequest $request): JsonResponse
    {
        try {
            $this->authorize('create', StoreProductVariantAddon::class);
            $seller = $this->ensureSeller();

            $result = $this->attachmentService->saveBulkAttachments(
                $seller,
                $request->validated()['attachments']
            );

            return ApiResponseType::sendJsonResponse(
                success: true,
                message: 'labels.product_addon_attached_successfully',
                data: [
                    'saved'        => $result['saved'],
                    'skipped'      => $result['skipped'],
                    'redirect_url' => route('seller.product-addons.index'),
                ],
                status: 201,
            );
        } catch (ValidationException $e) {
            return ApiResponseType::sendJsonResponse(false, 'labels.validation_failed', $e->errors(), 422);
        } catch (AuthorizationException) {
            return ApiResponseType::sendJsonResponse(false, 'labels.permission_denied', [], 403);
        } catch (SellerNotFoundException) {
            return ApiResponseType::sendJsonResponse(false, 'labels.seller_not_found', [], 404);
        } catch (Throwable $e) {
            Log::error('ProductAddon bulk attach failed', ['error' => $e->getMessage()]);
            return ApiResponseType::sendJsonResponse(false, 'labels.something_went_wrong', [], 500);
        }
    }

    /**
     * Update an existing attachment (same logic as store – upsert rows).
     */
    public function update(AttachProductAddonRequest $request, int $variantId, int $groupId): JsonResponse
    {
        try {
            $seller  = $this->ensureSeller();
            $variant = $this->resolveOwnedVariant($variantId);
            $group   = $this->resolveOwnedGroup($groupId);

            // Any existing row is sufficient for the update policy check.
            $existing = StoreProductVariantAddon::query()
                ->where('product_variant_id', $variant->id)
                ->where('addon_group_id', $group->id)
                ->first();
            if ($existing) {
                $this->authorize('update', $existing);
            } else {
                $this->authorize('create', StoreProductVariantAddon::class);
            }

            $this->attachmentService->saveAttachment($seller, $variant, $group, $request->validated()['stores']);

            return ApiResponseType::sendJsonResponse(
                success: true,
                message: 'labels.product_addon_updated_successfully',
                data: [
                    'redirect_url' => route('seller.product-addons.index'),
                ],
            );
        } catch (ValidationException $e) {
            return ApiResponseType::sendJsonResponse(false, 'labels.validation_failed', $e->errors(), 422);
        } catch (AuthorizationException) {
            return ApiResponseType::sendJsonResponse(false, 'labels.permission_denied', [], 403);
        } catch (ModelNotFoundException) {
            return ApiResponseType::sendJsonResponse(false, 'labels.product_addon_target_not_found', [], 404);
        } catch (Throwable $e) {
            Log::error('ProductAddon update failed', ['error' => $e->getMessage()]);
            return ApiResponseType::sendJsonResponse(false, 'labels.something_went_wrong', [], 500);
        }
    }

    /**
     * Detach every row for a (variant, group) pair.
     *
     * Works even when the AddonGroup or ProductVariant has been soft-deleted:
     * orphan attachment rows must remain deletable so the seller can clean up
     * stale listings that were created before cascade-delete was wired into
     * AddonGroupService.
     */
    public function destroy(int $variantId, int $groupId): JsonResponse
    {
        try {
            $seller  = $this->ensureSeller();
            $variant = $this->resolveOwnedVariant($variantId, allowTrashed: true);
            $group   = $this->resolveOwnedGroup($groupId, allowTrashed: true);

            $existing = StoreProductVariantAddon::query()
                ->where('product_variant_id', $variant->id)
                ->where('addon_group_id', $group->id)
                ->first();
            if ($existing) {
                $this->authorize('delete', $existing);
            }

            $this->attachmentService->detachAttachment($seller, $variant, $group);

            return ApiResponseType::sendJsonResponse(true, 'labels.product_addon_detached_successfully', []);
        } catch (AuthorizationException) {
            return ApiResponseType::sendJsonResponse(false, 'labels.permission_denied', [], 403);
        } catch (ModelNotFoundException $e) {
            return ApiResponseType::sendJsonResponse(false, 'labels.product_addon_target_not_found', [], 404);
        } catch (Throwable $e) {
            Log::error('ProductAddon detach failed', ['error' => $e->getMessage()]);
            return ApiResponseType::sendJsonResponse(false, 'labels.something_went_wrong', [], 500);
        }
    }

    // -----------------------------------------------------------------
    // AJAX lookups used by the form (Select2/TomSelect + matrix render)
    // -----------------------------------------------------------------

    /**
     * Search this seller's products.
     *
     * For variant-type products the `text` field is suffixed with the variant
     * count (e.g. "My Product (3 variants)") so sellers can distinguish them
     * from simple products directly in the TomSelect dropdown.
     */
    public function productLookup(Request $request): JsonResponse
    {
        $seller = $this->ensureSeller();
        $query  = (string) $request->input('q', '');

        $products = Product::query()
            ->where('seller_id', $seller->id)
            ->when($query !== '', fn ($q) => $q->where('title', 'like', "%{$query}%"))
            ->withCount('variants')
            ->orderBy('title')
            ->limit(15)
            ->get(['id', 'title', 'type']);

        return response()->json($products->map(function ($p) {
            $isVariant     = $p->type === ProductTypeEnum::VARIANT->value;
            $variantsCount = (int) ($p->variants_count ?? 0);
            $text          = $p->title;

            if ($isVariant && $variantsCount > 0) {
                $text .= ' ' . __('labels.product_addon_product_variants_suffix', ['count' => $variantsCount]);
            }

            return [
                'id'             => $p->id,
                'value'          => $p->id,
                'text'           => $text,
                'title'          => $p->title,
                'type'           => $p->type,
                'is_variant'     => $isVariant,
                'variants_count' => $variantsCount,
            ];
        }));
    }

    /**
     * Variants belonging to a given product (seller-owned).
     */
    public function variantLookup(Request $request, int $productId): JsonResponse
    {
        $seller  = $this->ensureSeller();
        $product = Product::where('seller_id', $seller->id)->findOrFail($productId);

        $variants = $product->variants()->get(['id', 'title']);

        return response()->json($variants->map(fn ($v) => [
            'id'         => $v->id,
            'value'      => $v->id,
            'text'       => $v->title ?: ('#' . $v->id),
            'product_id' => $product->id,
            'product'    => $product->title,
        ]));
    }

    /**
     * Variants across multiple products (single round trip for the multi-attach form).
     */
    public function variantsBulkLookup(Request $request): JsonResponse
    {
        $seller     = $this->ensureSeller();
        $productIds = array_filter((array) $request->input('product_ids', []), 'is_numeric');

        if (empty($productIds)) {
            return response()->json([]);
        }

        $variants = ProductVariant::query()
            ->whereIn('product_id', $productIds)
            ->whereHas('product', fn ($q) => $q->where('seller_id', $seller->id))
            ->with('product:id,title')
            ->get(['id', 'title', 'product_id']);

        return response()->json($variants->map(fn ($v) => [
            'id'         => $v->id,
            'value'      => $v->id,
            'text'       => ($v->product->title ?? '') . ' / ' . ($v->title ?: ('#' . $v->id)),
            'product_id' => $v->product_id,
            'product'    => $v->product->title ?? '',
        ]));
    }

    /**
     * Addon groups owned by this seller.
     */
    public function addonGroupLookup(Request $request): JsonResponse
    {
        $seller = $this->ensureSeller();
        $query  = (string) $request->input('q', '');

        $groups = AddonGroup::query()
            ->where('seller_id', $seller->id)
            ->where('status', 'active')
            ->when($query !== '', fn ($q) => $q->where('title', 'like', "%{$query}%"))
            ->orderBy('title')
            ->limit(15)
            ->get(['id', 'title']);

        return response()->json($groups->map(fn ($g) => [
            'id'    => $g->id,
            'value' => $g->id,
            'text'  => $g->title,
        ]));
    }

    /**
     * Return the full (stores × items) matrix payload the form needs to render.
     */
    public function matrix(Request $request, int $variantId, int $groupId): JsonResponse
    {
        $seller  = $this->ensureSeller();
        $variant = $this->resolveOwnedVariant($variantId);
        $group   = $this->resolveOwnedGroup($groupId);

        $payload = $this->attachmentService->buildFormPayload($seller, $variant, $group);

        return response()->json([
            'variant' => [
                'id'    => $variant->id,
                'title' => $variant->title,
            ],
            'group' => [
                'id'    => $group->id,
                'title' => $group->title,
            ],
            'stores' => $payload['stores']->map(fn (Store $s) => [
                'id'    => $s->id,
                'title' => $s->name ?? ('Store #' . $s->id),
            ]),
            'items' => $payload['items']->map(fn ($i) => [
                'id'        => $i->id,
                'title'     => $i->title,
                'price'     => (float) $i->price,
                'cost'      => $i->cost !== null ? (float) $i->cost : null,
            ]),
            'existing'  => $payload['existing']->values(),
            'inventory' => $payload['inventory']->values(),
        ]);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    protected function resolveOwnedVariant(int $variantId, bool $allowTrashed = false): ProductVariant
    {
        $seller = $this->ensureSeller();

        $query = ProductVariant::query()
            ->whereHas('product', fn ($q) => $q->where('seller_id', $seller->id))
            ->where('id', $variantId);

        if ($allowTrashed) {
            $query->withTrashed();
        }

        return $query->firstOrFail();
    }

    protected function resolveOwnedGroup(int $groupId, bool $allowTrashed = false): AddonGroup
    {
        $seller = $this->ensureSeller();

        $query = AddonGroup::query()
            ->where('seller_id', $seller->id)
            ->where('id', $groupId);

        if ($allowTrashed) {
            $query->withTrashed();
        }

        return $query->firstOrFail();
    }
}
