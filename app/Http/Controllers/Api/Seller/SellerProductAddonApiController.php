<?php

namespace App\Http\Controllers\Api\Seller;

use App\Enums\Addon\AddonGroupStatusEnum;
use App\Enums\Product\ProductTypeEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\ProductAddon\AttachProductAddonRequest;
use App\Http\Requests\ProductAddon\BulkAttachProductAddonRequest;
use App\Http\Requests\ProductAddon\ShowProductAddonMatrixRequest;
use App\Http\Resources\ProductAddonAttachmentResource;
use App\Models\AddonGroup;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\StoreProductVariantAddon;
use App\Services\ProductAddonAttachmentService;
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

#[Group('Seller Product Addons')]
class SellerProductAddonApiController extends Controller
{
    use AuthorizesRequests;

    public function __construct(protected ProductAddonAttachmentService $attachmentService)
    {
    }

    /**
     * List distinct (variant × addon_group) attachments for the authenticated seller.
     *
     * @return JsonResponse
     */
    #[QueryParameter('page', description: 'Page number for pagination.', type: 'int', default: 1, example: 1)]
    #[QueryParameter('per_page', description: 'Items per page.', type: 'int', default: 15, example: 15)]
    #[QueryParameter('search', description: 'Search by product, variant or group title.', type: 'string', example: 'Pizza')]
    public function index(Request $request): JsonResponse
    {
        try {
            $this->authorize('viewAny', StoreProductVariantAddon::class);

            $user = auth()->user();
            $seller = $user?->seller();
            if (!$seller) {
                return ApiResponseType::sendJsonResponse(false, 'labels.seller_not_found', null, 404);
            }

            $perPage = (int)$request->input('per_page', 15);
            $search = trim((string)$request->input('search', ''));

            $paginator = $this->attachmentService
                ->listAttachments($seller, $search !== '' ? $search : null)
                ->paginate($perPage);

            $paginator->getCollection()->transform(fn($row) => new ProductAddonAttachmentResource($row));

            return ApiResponseType::sendJsonResponse(
                true,
                'labels.product_addons_fetched_successfully',
                ApiResponseType::responseFromPaginator($paginator),
            );
        } catch (AuthorizationException) {
            return ApiResponseType::sendJsonResponse(false, 'labels.permission_denied', [], 403);
        } catch (Throwable $e) {
            Log::error('Seller product addons index error', ['error' => $e->getMessage()]);
            return ApiResponseType::sendJsonResponse(false, 'labels.failed_to_fetch_product_addons', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Show the full matrix (stores × items × existing overrides) for one or more
     * (variant, group) pairs in a single request.
     *
     * Submitted as a POST JSON body so the nested payload travels cleanly
     * without URL-encoding gymnastics:
     *
     *   { "pairs": [ { "variant_id": 12, "group_id": 34 }, ... ] }
     *
     * The response always contains a `matrices` array — one entry per requested
     * pair, in the same order they were submitted. Pairs that reference a
     * variant or group not owned by the authenticated seller are reported in a
     * sibling `not_found` array (with the offending ids) instead of failing the
     * whole request, so a partial fetch can still surface the rest.
     *
     * Each entry in `matrices` has the same shape that the previous single-pair
     * endpoint returned (variant, group, stores, items, existing, inventory)
     * so existing client-side renderers continue to work unchanged.
     *
     * @return JsonResponse
     */
    public function show(ShowProductAddonMatrixRequest $request): JsonResponse
    {
        try {
            $this->authorize('viewAny', StoreProductVariantAddon::class);

            $seller = $this->resolveSellerOrFail();
            if ($seller instanceof JsonResponse) {
                return $seller;
            }

            $pairs = $request->validated()['pairs'];

            // Pre-load the seller's variants + groups in two queries so we
            // don't fire two SELECTs per pair when the client batches.
            $variantIds = collect($pairs)->pluck('variant_id')->unique()->values()->all();
            $groupIds = collect($pairs)->pluck('group_id')->unique()->values()->all();

            $variants = ProductVariant::query()
                ->whereIn('id', $variantIds)
                ->whereHas('product', fn($q) => $q->where('seller_id', $seller->id))
                ->get()
                ->keyBy('id');

            $groups = AddonGroup::query()
                ->whereIn('id', $groupIds)
                ->where('seller_id', $seller->id)
                ->get()
                ->keyBy('id');

            $matrices = [];
            $notFound = [];

            foreach ($pairs as $pair) {
                $variantId = (int)$pair['variant_id'];
                $groupId = (int)$pair['group_id'];

                $variant = $variants->get($variantId);
                $group = $groups->get($groupId);

                if (!$variant || !$group) {
                    $notFound[] = [
                        'variant_id' => $variantId,
                        'group_id' => $groupId,
                    ];
                    continue;
                }

                $payload = $this->attachmentService->buildFormPayload($seller, $variant, $group);
                $matrices[] = $this->formatMatrixPayload($payload);
            }

            return ApiResponseType::sendJsonResponse(
                true,
                'labels.product_addon_matrices_fetched_successfully',
                [
                    'matrices' => $matrices,
                    'not_found' => $notFound,
                ],
            );
        } catch (AuthorizationException) {
            return ApiResponseType::sendJsonResponse(false, 'labels.permission_denied', [], 403);
        } catch (Throwable $e) {
            Log::error('Seller product addon show error', ['error' => $e->getMessage()]);
            return ApiResponseType::sendJsonResponse(false, 'labels.failed_to_fetch_product_addon', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Bulk attach multiple (variant × addon_group) combinations in a single submission.
     *
     * @return JsonResponse
     */
    public function store(BulkAttachProductAddonRequest $request): JsonResponse
    {
        try {
            $this->authorize('create', StoreProductVariantAddon::class);

            $seller = $this->resolveSellerOrFail();
            if ($seller instanceof JsonResponse) {
                return $seller;
            }

            $result = $this->attachmentService->saveBulkAttachments(
                $seller,
                $request->validated()['attachments']
            );

            return ApiResponseType::sendJsonResponse(
                true,
                'labels.product_addon_attached_successfully',
                [
                    'saved' => $result['saved'],
                    'skipped' => $result['skipped'],
                ],
                201,
            );
        } catch (AuthorizationException) {
            return ApiResponseType::sendJsonResponse(false, 'labels.permission_denied', [], 403);
        } catch (Throwable $e) {
            Log::error('Seller product addon bulk attach error', ['error' => $e->getMessage()]);
            return ApiResponseType::sendJsonResponse(false, 'labels.failed_to_save_product_addon', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Update an existing (variant × addon_group) attachment's per-store overrides.
     *
     * @param int $variantId Product variant ID.
     * @param int $groupId Addon group ID.
     * @return JsonResponse
     */
    public function update(AttachProductAddonRequest $request, int $variantId, int $groupId): JsonResponse
    {
        try {
            $seller = $this->resolveSellerOrFail();
            if ($seller instanceof JsonResponse) {
                return $seller;
            }

            $variant = $this->resolveOwnedVariant($seller, $variantId);
            $group = $this->resolveOwnedGroup($seller, $groupId);

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
                true,
                'labels.product_addon_updated_successfully',
                $this->formatMatrixPayload($this->attachmentService->buildFormPayload($seller, $variant, $group)),
            );
        } catch (ModelNotFoundException) {
            return ApiResponseType::sendJsonResponse(false, 'labels.product_addon_target_not_found', [], 404);
        } catch (AuthorizationException) {
            return ApiResponseType::sendJsonResponse(false, 'labels.permission_denied', [], 403);
        } catch (Throwable $e) {
            Log::error('Seller product addon update error', ['error' => $e->getMessage()]);
            return ApiResponseType::sendJsonResponse(false, 'labels.failed_to_update_product_addon', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Detach all rows for a (variant × addon_group) pair across the seller's stores.
     *
     * @param int $variantId Product variant ID.
     * @param int $groupId Addon group ID.
     * @return JsonResponse
     */
    public function destroy(int $variantId, int $groupId): JsonResponse
    {
        try {
            $seller = $this->resolveSellerOrFail();
            if ($seller instanceof JsonResponse) {
                return $seller;
            }

            $variant = $this->resolveOwnedVariant($seller, $variantId);
            $group = $this->resolveOwnedGroup($seller, $groupId);

            $existing = StoreProductVariantAddon::query()
                ->where('product_variant_id', $variant->id)
                ->where('addon_group_id', $group->id)
                ->first();
            if ($existing) {
                $this->authorize('delete', $existing);
            }

            $this->attachmentService->detachAttachment($seller, $variant, $group);

            return ApiResponseType::sendJsonResponse(true, 'labels.product_addon_detached_successfully', []);
        } catch (ModelNotFoundException) {
            return ApiResponseType::sendJsonResponse(false, 'labels.product_addon_target_not_found', [], 404);
        } catch (AuthorizationException) {
            return ApiResponseType::sendJsonResponse(false, 'labels.permission_denied', [], 403);
        } catch (Throwable $e) {
            Log::error('Seller product addon detach error', ['error' => $e->getMessage()]);
            return ApiResponseType::sendJsonResponse(false, 'labels.failed_to_delete_product_addon', ['error' => $e->getMessage()], 500);
        }
    }

    // -----------------------------------------------------------------
    // Lookups (for mobile clients to populate pickers + build the matrix)
    // -----------------------------------------------------------------

    /**
     * Search seller-owned products (used to populate the product picker).
     *
     * @return JsonResponse
     */
    #[QueryParameter('search', description: 'Search term for product title.', type: 'string', example: 'Pizza')]
    #[QueryParameter('limit', description: 'Max results (capped at 50).', type: 'int', default: 15, example: 15)]
    #[QueryParameter('page', description: 'Page.', type: 'int', default: 1, example: 1)]
    public function productLookup(Request $request): JsonResponse
    {
        try {
            $this->authorize('viewAny', StoreProductVariantAddon::class);

            $seller = $this->resolveSellerOrFail();
            if ($seller instanceof JsonResponse) {
                return $seller;
            }

            $query = trim((string)$request->input('search', ''));
            $limit = min(50, max(1, (int)$request->input('limit', 15)));

            $products = Product::query()
                ->where('seller_id', $seller->id)
                ->when($query !== '', fn($q) => $q->where('title', 'like', "%{$query}%"))
                ->withCount('variants')
                ->orderBy('title')
                ->paginate($limit);

            $data = $products->getCollection()->map(function ($p) {
                $isVariant = $p->type === ProductTypeEnum::VARIANT->value;
                $variantsCount = (int)($p->variants_count ?? 0);

                return [
                    'id' => (int)$p->id,
                    'title' => (string)$p->title,
                    'type' => (string)$p->type,
                    'is_variant' => $isVariant,
                    'variants_count' => $variantsCount,
                ];
            });

            return ApiResponseType::sendJsonResponse(
                true,
                'labels.products_fetched_successfully',
                [
                    'current_page' => $products->currentPage(),
                    'last_page' => $products->lastPage(),
                    'per_page' => $products->perPage(),
                    'total' => $products->total(),
                    'items' => $data,
                ],
            );

        } catch (AuthorizationException) {
            return ApiResponseType::sendJsonResponse(false, 'labels.permission_denied', [], 403);
        } catch (Throwable $e) {
            Log::error('Seller product addon product lookup error', ['error' => $e->getMessage()]);
            return ApiResponseType::sendJsonResponse(false, 'labels.failed_to_fetch_products', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * List variants for a specific seller-owned product.
     *
     * @param int $productId Product ID.
     * @return JsonResponse
     */
    public function variantLookup(int $productId): JsonResponse
    {
        try {
            $this->authorize('viewAny', StoreProductVariantAddon::class);

            $seller = $this->resolveSellerOrFail();
            if ($seller instanceof JsonResponse) {
                return $seller;
            }

            $product = Product::where('seller_id', $seller->id)->findOrFail($productId);
            $variants = $product->variants()->get(['id', 'title']);

            $data = $variants->map(fn($v) => [
                'id' => (int)$v->id,
                'title' => $v->title ?: ('#' . $v->id),
                'product_id' => (int)$product->id,
                'product' => (string)$product->title,
            ]);

            return ApiResponseType::sendJsonResponse(
                true,
                'labels.variants_fetched_successfully',
                $data,
            );
        } catch (ModelNotFoundException) {
            return ApiResponseType::sendJsonResponse(false, 'labels.product_not_found', [], 404);
        } catch (AuthorizationException) {
            return ApiResponseType::sendJsonResponse(false, 'labels.permission_denied', [], 403);
        } catch (Throwable $e) {
            Log::error('Seller product addon variant lookup error', ['error' => $e->getMessage()]);
            return ApiResponseType::sendJsonResponse(false, 'labels.failed_to_fetch_variants', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Return variants across many products in one round-trip (used by the bulk attach form).
     *
     * @return JsonResponse
     */
    #[QueryParameter('product_ids', description: 'Comma-separated list or array of product IDs.', type: 'string', example: '10,11,12')]
    public function variantsBulkLookup(Request $request): JsonResponse
    {
        try {
            $this->authorize('viewAny', StoreProductVariantAddon::class);

            $seller = $this->resolveSellerOrFail();
            if ($seller instanceof JsonResponse) {
                return $seller;
            }

            $raw = $request->input('product_ids', []);
            if (is_string($raw)) {
                $raw = array_filter(array_map('trim', explode(',', $raw)), fn($v) => $v !== '');
            }
            $productIds = array_values(array_filter((array)$raw, 'is_numeric'));

            if (empty($productIds)) {
                return ApiResponseType::sendJsonResponse(
                    true,
                    'labels.variants_fetched_successfully',
                    [],
                );
            }

            $variants = ProductVariant::query()
                ->whereIn('product_id', $productIds)
                ->whereHas('product', fn($q) => $q->where('seller_id', $seller->id))
                ->with('product:id,title')
                ->get(['id', 'title', 'product_id']);

            $data = $variants->map(fn($v) => [
                'id' => (int)$v->id,
                'title' => $v->title ?: ('#' . $v->id),
                'product_id' => (int)$v->product_id,
                'product' => (string)($v->product->title ?? ''),
            ]);

            return ApiResponseType::sendJsonResponse(
                true,
                'labels.variants_fetched_successfully',
                $data,
            );
        } catch (AuthorizationException) {
            return ApiResponseType::sendJsonResponse(false, 'labels.permission_denied', [], 403);
        } catch (Throwable $e) {
            Log::error('Seller product addon variants-bulk lookup error', ['error' => $e->getMessage()]);
            return ApiResponseType::sendJsonResponse(false, 'labels.failed_to_fetch_variants', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * List active addon groups owned by the authenticated seller.
     *
     * @return JsonResponse
     */
    #[QueryParameter('search', description: 'Search by group title.', type: 'string', example: 'Toppings')]
    #[QueryParameter('limit', description: 'Max results (capped at 50).', type: 'int', default: 15, example: 15)]
    #[QueryParameter('page', description: 'Page.', type: 'int', default: 1, example: 1)]
    public function addonGroupLookup(Request $request): JsonResponse
    {
        try {
            $this->authorize('viewAny', StoreProductVariantAddon::class);

            $seller = $this->resolveSellerOrFail();
            if ($seller instanceof JsonResponse) {
                return $seller;
            }

            $query = trim((string)$request->input('search', ''));
            $limit = min(50, max(1, (int)$request->input('limit', 15)));

            $groups = AddonGroup::query()
                ->where('seller_id', $seller->id)
                ->where('status', AddonGroupStatusEnum::ACTIVE->value)
                ->when($query !== '', fn($q) => $q->where('title', 'like', "%{$query}%"))
                ->orderBy('title')
                ->paginate($limit, ['id', 'title']); // 👈 pagination

            $data = $groups->getCollection()->map(fn($g) => [
                'id' => (int)$g->id,
                'title' => (string)$g->title,
            ]);

            return ApiResponseType::sendJsonResponse(
                true,
                'labels.addon_groups_fetched_successfully',
                [
                    'current_page' => $groups->currentPage(),
                    'last_page'    => $groups->lastPage(),
                    'per_page'     => $groups->perPage(),
                    'total'        => $groups->total(),
                    'items' => $data,
                ],
            );

        } catch (AuthorizationException) {
            return ApiResponseType::sendJsonResponse(false, 'labels.permission_denied', [], 403);
        } catch (Throwable $e) {
            Log::error('Seller product addon group lookup error', ['error' => $e->getMessage()]);
            return ApiResponseType::sendJsonResponse(false, 'labels.failed_to_fetch_addon_groups', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Return the matrix payload (stores × items × existing overrides) for the
     * (variant, group) pair that the given product-addon row belongs to.
     *
     * @param int $id Product-addon row ID (as returned in the listing payload).
     * @return JsonResponse
     */
    public function matrix(int $id): JsonResponse
    {
        try {
            $this->authorize('viewAny', StoreProductVariantAddon::class);

            $seller = $this->resolveSellerOrFail();
            if ($seller instanceof JsonResponse) {
                return $seller;
            }

            $attachment = $this->resolveOwnedAttachment($seller, $id);

            $variant = $this->resolveOwnedVariant($seller, (int)$attachment->product_variant_id);
            $group = $this->resolveOwnedGroup($seller, (int)$attachment->addon_group_id);

            $payload = $this->attachmentService->buildFormPayload($seller, $variant, $group);

            return ApiResponseType::sendJsonResponse(
                true,
                'labels.product_addon_matrix_fetched_successfully',
                $this->formatMatrixPayload($payload),
            );
        } catch (ModelNotFoundException) {
            return ApiResponseType::sendJsonResponse(false, 'labels.product_addon_target_not_found', [], 404);
        } catch (AuthorizationException) {
            return ApiResponseType::sendJsonResponse(false, 'labels.permission_denied', [], 403);
        } catch (Throwable $e) {
            Log::error('Seller product addon matrix error', ['error' => $e->getMessage()]);
            return ApiResponseType::sendJsonResponse(false, 'labels.failed_to_fetch_product_addon', ['error' => $e->getMessage()], 500);
        }
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * Normalize the service payload into an API-friendly shape.
     */
    protected function formatMatrixPayload(array $payload): array
    {
        /** @var ProductVariant $variant */
        $variant = $payload['variant'];
        /** @var AddonGroup $group */
        $group = $payload['group'];

        return [
            'variant' => [
                'id' => (int)$variant->id,
                'title' => $variant->title ?: ('#' . $variant->id),
            ],
            'group' => [
                'id' => (int)$group->id,
                'title' => (string)$group->title,
            ],
            'stores' => $payload['stores']->map(fn(Store $s) => [
                'id' => (int)$s->id,
                'title' => $s->name ?? ('Store #' . $s->id),
            ])->values(),
            'items' => $payload['items']->map(fn($i) => [
                'id' => (int)$i->id,
                'title' => (string)$i->title,
                'price' => (float)$i->price,
                'cost' => $i->cost !== null ? (float)$i->cost : null,
            ])->values(),
            'existing' => $payload['existing']->values(),
            'inventory' => $payload['inventory']->values(),
        ];
    }

    /**
     * Resolve the authenticated seller or return a 404 JsonResponse.
     *
     * @return \App\Models\Seller|JsonResponse
     */
    protected function resolveSellerOrFail()
    {
        $user = auth()->user();
        $seller = $user?->seller();
        if (!$seller) {
            return ApiResponseType::sendJsonResponse(false, 'labels.seller_not_found', null, 404);
        }

        return $seller;
    }

    protected function resolveOwnedVariant($seller, int $variantId): ProductVariant
    {
        return ProductVariant::query()
            ->whereHas('product', fn($q) => $q->where('seller_id', $seller->id))
            ->where('id', $variantId)
            ->firstOrFail();
    }

    protected function resolveOwnedGroup($seller, int $groupId): AddonGroup
    {
        return AddonGroup::query()
            ->where('seller_id', $seller->id)
            ->where('id', $groupId)
            ->firstOrFail();
    }

    /**
     * Look up a product-addon row by id and make sure it belongs to one of the
     * seller's stores. Returns the SPVA model so callers can read its
     * product_variant_id / addon_group_id to locate the logical attachment.
     */
    protected function resolveOwnedAttachment($seller, int $id): StoreProductVariantAddon
    {
        return StoreProductVariantAddon::query()
            ->whereHas('store', fn($q) => $q->where('seller_id', $seller->id))
            ->where('id', $id)
            ->firstOrFail();
    }
}
