<?php

namespace App\Services;

use App\Enums\Addon\AddonGroupSelectionTypeEnum;
use App\Enums\PromoDiscountTypeEnum;
use App\Enums\PromoModeEnum;
use App\Enums\SettingTypeEnum;
use App\Events\Cart\CartUpdatedByLocation;
use App\Events\Cart\ItemAddedToCart;
use App\Events\Cart\ItemRemovedFromCart;
use App\Http\Resources\Product\ProductListResource;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\CartItemAddon;
use App\Models\Order;
use App\Models\OrderPromoLine;
use App\Models\Promo;
use App\Models\StoreProductVariant;
use App\Models\StoreProductVariantAddon;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CartService
{
    public function __construct(
        protected ?StoreAddonItemService $storeAddonItemService = null,
    ) {
        // Keep constructor injection optional so Laravel's container can resolve
        // the service in controllers without breaking the dozens of existing
        // `new CartService()` call sites (reorder, syncMultiStoreCart, etc.).
        $this->storeAddonItemService ??= app(StoreAddonItemService::class);
    }

    /**
     * Add item to cart
     *
     * Supports an optional `addons` array on $data:
     *   ['addons' => [['addon_group_id' => 1, 'addon_item_id' => 10], ...]]
     *
     * When present, the selections are validated against
     * `store_product_variant_addons` (is the item attached to the variant at
     * this store?), the per-group rules (SINGLE/MULTIPLE, is_required) are
     * enforced, and per-store pricing is snapshotted into `cart_item_addons`.
     * Two add calls with the same (product, variant, store) but different
     * addon sets create separate cart lines; same set → qty merges.
     */
    public function addToCart(User $user, array $data): array
    {
        try {
            // Verify product variant exists in selected store
            $storeProductVariant = $this->getStoreProductVariant((int) $data['store_id'], (int) $data['product_variant_id']);
            if (! $storeProductVariant || empty($storeProductVariant['productVariant'])) {
                return [
                    'success' => false,
                    'message' => __('messages.product_variant_not_available_in_store'),
                    'data' => [],
                ];
            }

            // Check if the store is online
            if ($this->isStoreOffline($storeProductVariant)) {
                return [
                    'success' => false,
                    'message' => __('messages.store_offline_cannot_add_to_cart'),
                    'data' => ['store_id' => $storeProductVariant->store->id],
                ];
            }

            // Validate + resolve the addon selections (if any). This also
            // enforces required/selection_type rules for the variant so we
            // can reject before creating or mutating any cart rows.
            $addonResolution = $this->resolveAddonSelections(
                storeId: (int) $data['store_id'],
                productVariantId: (int) $data['product_variant_id'],
                addons: is_array($data['addons'] ?? null) ? $data['addons'] : [],
            );
            if (! $addonResolution['success']) {
                return $addonResolution['error'];
            }
            $resolvedAddons = $addonResolution['resolved'];
            $addonSignature = CartItem::buildAddonSignature(
                array_column($resolvedAddons, 'addon_item_id')
            );

            // Get or create cart for user
            $cart = Cart::firstOrCreate(
                ['user_id' => $user->id],
                ['uuid' => Str::uuid()->toString()]
            );

            // Existing line match is scoped by addon signature — lines with
            // different addon selections are treated as distinct items.
            $cartItem = CartItem::where('cart_id', $cart->id)
                ->where('product_id', $storeProductVariant['productVariant']['product_id'])
                ->where('product_variant_id', $data['product_variant_id'])
                ->where('store_id', $data['store_id'])
                ->where(function ($q) use ($addonSignature) {
                    if ($addonSignature === null) {
                        $q->whereNull('addon_signature');
                    } else {
                        $q->where('addon_signature', $addonSignature);
                    }
                })
                ->first();
            $userCart = $this->getUserCart($user);

            // Validate checkout type (single or multi store) similar to OrderService::validateCartAndSettings
            $singleStoreValidation = $this->validateSingleStoreCheckout($userCart, (int) $data['store_id']);
            if ($singleStoreValidation !== null) {
                return $singleStoreValidation;
            }

            $requestedQuantity = $data['quantity'] ?? 1;
            // If true, the incoming quantity should REPLACE current quantity (used by cart sync)
            $replaceQuantity = (bool) ($data['replace_quantity'] ?? false);
            DB::beginTransaction();
            if ($cartItem) {
                if ($cartItem->save_for_later === true) {
                    $res = $this->validateCartMaxItems($userCart);
                    if (! $res) {
                        return [
                            'success' => false,
                            'message' => __('messages.maximum_items_allowed_in_cart_reached'),
                            'data' => [],
                        ];
                    }
                    $cartItem->update(['save_for_later' => '0']);
                }

                // Determine the final quantity based on replace/increment behavior
                $newQuantity = $replaceQuantity ? $requestedQuantity : ($cartItem->quantity + $requestedQuantity);

                // Validate quantity rules and stock
                $rules = $this->extractQuantityRules($storeProductVariant);
                if ($error = $this->validateQuantityRules($newQuantity, $rules)) {
                    return $error;
                }
                if ($error = $this->checkStock($newQuantity, (int) $storeProductVariant->stock)) {
                    return $error;
                }
                // Each selected addon is consumed once per product unit — reject
                // early if any store_addon_items row doesn't have enough stock
                // to cover the merged quantity.
                if ($error = $this->checkAddonsStock($resolvedAddons, $newQuantity)) {
                    return $error;
                }

                $cartItem->update(['quantity' => $newQuantity]);
                // Signature already matches — addon rows are unchanged on a
                // quantity merge. No need to touch cart_item_addons.
            } else {
                // Validate product quantity rules and stock for new cart item
                $rules = $this->extractQuantityRules($storeProductVariant);
                if ($error = $this->validateQuantityRules($requestedQuantity, $rules)) {
                    return $error;
                }
                if ($error = $this->checkStock($requestedQuantity, (int) $storeProductVariant->stock)) {
                    return $error;
                }
                if ($error = $this->checkAddonsStock($resolvedAddons, $requestedQuantity)) {
                    return $error;
                }
                $res = $this->validateCartMaxItems($userCart);
                if (! $res) {
                    return [
                        'success' => false,
                        'message' => __('messages.maximum_items_allowed_in_cart_reached'),
                        'data' => [],
                    ];
                }
                // Create new cart item
                $cartItem = CartItem::create([
                    'cart_id' => $cart->id,
                    'product_id' => $storeProductVariant['productVariant']['product_id'],
                    'product_variant_id' => $data['product_variant_id'],
                    'store_id' => $data['store_id'],
                    'quantity' => $requestedQuantity,
                    'addon_signature' => $addonSignature,
                    'save_for_later' => '0', // Changed from false to '0'
                ]);

                // Snapshot the resolved addons against the new line.
                $this->persistCartItemAddons($cartItem, $resolvedAddons);
            }

            // Load cart with items
            $cart->load(['items.product', 'items.variant', 'items.store', 'items.addons']);

            // Fire event
            event(new ItemAddedToCart($cart, $cartItem, $user));

            DB::commit();

            return [
                'success' => true,
                'message' => __('messages.item_added_to_cart_successfully'),
                'data' => $cart,
            ];

        } catch (\Exception $e) {
            DB::rollBack();

            return [
                'success' => false,
                'message' => __('labels.something_went_wrong'),
                'data' => ['error' => $e->getMessage()],
            ];
        }
    }

    /**
     * Validate the submitted addon selections against the variant's
     * `store_product_variant_addons` attachments and resolve the store-level
     * price snapshot for each one.
     *
     * Returns either:
     *   ['success' => true, 'resolved' => [['addon_group_id' => …, 'addon_item_id' => …, 'price' => …, 'stock' => …], ...]]
     * or:
     *   ['success' => false, 'error' => <standard service error response>]
     *
     * Each resolved row carries the store-level `stock` value (nullable — `null`
     * when the addon falls back to the catalog, since the catalog doesn't
     * track stock). Callers are expected to validate stock against the final
     * cart-line quantity via {@see checkAddonsStock()} once that quantity is known.
     *
     * Semantics enforced:
     *  - every submitted (group, item) pair must be attached to the variant at this store
     *  - SINGLE groups may not carry more than one item
     *  - every required group attached to the variant must have a selection
     *  - the addon must be available (store_addon_items.is_available when the
     *    per-store row exists; fallback to catalog addon_items.is_available)
     *
     * @param  array<int,array{addon_group_id:int|string,addon_item_id:int|string}>  $addons
     */
    private function resolveAddonSelections(int $storeId, int $productVariantId, array $addons): array
    {
        // Attachments available for this (store, variant). This is the
        // authoritative set the user can choose from.
        $attached = StoreProductVariantAddon::with(['addonGroup', 'addonItem'])
            ->where('store_id', $storeId)
            ->where('product_variant_id', $productVariantId)
            ->get();

        // Client may submit duplicate (group,item) pairs — fold them.
        $pairs = collect($addons)
            ->map(fn ($a) => [
                'addon_group_id' => (int) ($a['addon_group_id'] ?? 0),
                'addon_item_id' => (int) ($a['addon_item_id'] ?? 0),
            ])
            ->filter(fn ($p) => $p['addon_group_id'] > 0 && $p['addon_item_id'] > 0)
            ->unique(fn ($p) => $p['addon_group_id'].':'.$p['addon_item_id'])
            ->values();

        // (group, item) -> StoreProductVariantAddon row
        $attachedMap = $attached->keyBy(
            fn ($row) => ((int) $row->addon_group_id).':'.((int) $row->addon_item_id)
        );

        // Every submitted pair must be attached to the variant at this store.
        foreach ($pairs as $pair) {
            $key = $pair['addon_group_id'].':'.$pair['addon_item_id'];
            if (! $attachedMap->has($key)) {
                return [
                    'success' => false,
                    'error' => [
                        'success' => false,
                        'message' => __('messages.addon_not_available_for_variant'),
                        'data' => [
                            'addon_group_id' => $pair['addon_group_id'],
                            'addon_item_id' => $pair['addon_item_id'],
                        ],
                    ],
                ];
            }
        }

        $byGroup = $pairs->groupBy('addon_group_id');
        $groupsIndexed = $attached->pluck('addonGroup')->filter()->unique('id')->keyBy('id');

        // SINGLE selection rule.
        foreach ($byGroup as $groupId => $groupPairs) {
            $group = $groupsIndexed->get((int) $groupId);
            if (! $group) {
                continue; // Shouldn't happen — already validated as attached.
            }
            $selectionType = $group->selection_type;
            $selType = $selectionType instanceof AddonGroupSelectionTypeEnum
                ? $selectionType
                : (is_string($selectionType) ? AddonGroupSelectionTypeEnum::tryFrom($selectionType) : null);

            if ($selType === AddonGroupSelectionTypeEnum::SINGLE && $groupPairs->count() > 1) {
                return [
                    'success' => false,
                    'error' => [
                        'success' => false,
                        'message' => __('messages.addon_group_single_selection_required', ['group' => $group->title]),
                        'data' => ['addon_group_id' => (int) $groupId],
                    ],
                ];
            }
        }

        // Required-group rule: any required group attached to the variant
        // must be represented in the user's selection.
        foreach ($groupsIndexed as $gid => $group) {
            if ((bool) $group->is_required && ! $byGroup->has((int) $gid)) {
                return [
                    'success' => false,
                    'error' => [
                        'success' => false,
                        'message' => __('messages.addon_group_required_missing', ['group' => $group->title]),
                        'data' => ['addon_group_id' => (int) $gid],
                    ],
                ];
            }
        }

        if ($pairs->isEmpty()) {
            return ['success' => true, 'resolved' => []];
        }

        // Resolve pricing + availability from store_addon_items with catalog fallback.
        $resolved = [];
        foreach ($pairs as $pair) {
            $attachment = $attachedMap->get($pair['addon_group_id'].':'.$pair['addon_item_id']);
            $inventory = $this->storeAddonItemService->findForCheckout($storeId, $pair['addon_item_id']);

            if ($inventory) {
                if (! $inventory->is_available) {
                    return [
                        'success' => false,
                        'error' => [
                            'success' => false,
                            'message' => __('messages.addon_item_unavailable'),
                            'data' => ['addon_item_id' => $pair['addon_item_id']],
                        ],
                    ];
                }
                $price = (float) $inventory->price;
                $stock = (int) $inventory->stock;
            } else {
                // No per-store row — fall back to the catalog defaults.
                $catalog = $attachment?->addonItem;
                if (! $catalog || ! (bool) ($catalog->is_available ?? true)) {
                    return [
                        'success' => false,
                        'error' => [
                            'success' => false,
                            'message' => __('messages.addon_item_unavailable'),
                            'data' => ['addon_item_id' => $pair['addon_item_id']],
                        ],
                    ];
                }
                $price = (float) ($catalog->price ?? 0);
                // Catalog rows don't track stock — leave it null so the
                // downstream stock check can skip this line.
                $stock = null;
            }

            $resolved[] = [
                'addon_group_id' => $pair['addon_group_id'],
                'addon_item_id' => $pair['addon_item_id'],
                'price' => $price,
                'stock' => $stock,
            ];
        }

        return ['success' => true, 'resolved' => $resolved];
    }

    /**
     * Ensure there is enough per-store stock for every resolved addon given
     *
     * @param  array<int,array{addon_group_id:int,addon_item_id:int,price:float,stock:int|null}>  $resolvedAddons
     */
    private function checkAddonsStock(array $resolvedAddons, int $quantity): ?array
    {
        foreach ($resolvedAddons as $addon) {
            $stock = $addon['stock'] ?? null;
            if ($stock === null || $stock < $quantity) {
                return [
                    'success' => false,
                    'message' => __('messages.addon_item_insufficient_stock'),
                    'data' => [
                        'addon_item_id' => $addon['addon_item_id'],
                        'available_stock' => (int) $stock,
                        'requested' => $quantity,
                    ],
                ];
            }
        }

        return null;
    }

    /**
     * Re-validate every addon snapshot on a cart against the current store
     * catalog + inventory. This is the place-order equivalent of the checks
     * {@see resolveAddonSelections()} performs when a user adds to cart — we
     * run them again because (a) the cart snapshot may be stale, (b) the
     * seller may have toggled availability or detached addons since, and
     * (c) another order may have consumed the addon stock the user is
     * relying on.
     *
     * Returns `null` on success, or a standard service error envelope on the
     * first failed check (same shape as `addToCart` errors so OrderService
     * can forward it verbatim).
     */
    public function validateCartAddonsForCheckout(Cart $cart): ?array
    {
        foreach ($cart->items as $cartItem) {
            if ($cartItem->save_for_later === true || (string) $cartItem->save_for_later === '1') {
                continue;
            }

            $existingAddons = $cartItem->relationLoaded('addons')
                ? $cartItem->getRelation('addons')
                : $cartItem->addons()->get();

            // Build a validation payload from the stored rows so the regular
            // resolver performs attachment + availability checks for us.
            $payload = $existingAddons->map(fn (CartItemAddon $addon) => [
                'addon_group_id' => (int) $addon->addon_group_id,
                'addon_item_id' => (int) $addon->addon_item_id,
            ])->all();

            $resolution = $this->resolveAddonSelections(
                storeId: (int) $cartItem->store_id,
                productVariantId: (int) $cartItem->product_variant_id,
                addons: $payload,
            );
            if (! $resolution['success']) {
                return $resolution['error'];
            }

            // Stock is the only dimension scaled by quantity — same rule the
            // cart enforces on add/update: stock >= line quantity.
            if ($error = $this->checkAddonsStock($resolution['resolved'], (int) $cartItem->quantity)) {
                return $error;
            }
        }

        return null;
    }

    /**
     * Stock check for the addons already attached to a cart line — used on
     * quantity updates that don't pass a fresh addons payload. Pulls the
     * per-store inventory for each existing `cart_item_addons` row and
     * enforces `stock >= quantity` when a `store_addon_items` row exists.
     * Catalog-only addons (no per-store row) are skipped.
     */
    private function checkExistingAddonsStock(CartItem $cartItem, int $quantity): ?array
    {
        $existing = $cartItem->addons()->get();

        if ($existing->isEmpty()) {
            return null;
        }

        $resolved = $existing->map(function (CartItemAddon $addon) use ($cartItem) {
            $inventory = $this->storeAddonItemService->findForCheckout(
                (int) $cartItem->store_id,
                (int) $addon->addon_item_id,
            );

            return [
                'addon_group_id' => (int) $addon->addon_group_id,
                'addon_item_id' => (int) $addon->addon_item_id,
                'price' => (float) $addon->price,
                'stock' => $inventory ? (int) $inventory->stock : null,
            ];
        })->all();

        return $this->checkAddonsStock($resolved, $quantity);
    }

    /**
     * Persist resolved addon selections against a cart line.
     *
     * @param  array<int,array{addon_group_id:int,addon_item_id:int,price:float}>  $resolvedAddons
     */
    private function persistCartItemAddons(CartItem $cartItem, array $resolvedAddons): void
    {
        // Clear existing snapshot rows (if any) before writing the new set.
        $cartItem->addons()->delete();

        foreach ($resolvedAddons as $addon) {
            CartItemAddon::create([
                'cart_item_id' => $cartItem->id,
                'addon_group_id' => $addon['addon_group_id'],
                'addon_item_id' => $addon['addon_item_id'],
                'price' => $addon['price'],
            ]);
        }
    }

    /**
     * Helper: fetch store product variant with relations and stock > 0
     */
    private function getStoreProductVariant(int $storeId, int $productVariantId): ?StoreProductVariant
    {
        return StoreProductVariant::where('store_id', $storeId)
            ->where('product_variant_id', $productVariantId)
            ->where('stock', '>', 0)
            ->with(['productVariant', 'store'])
            ->first();
    }

    /**
     * Helper: whether store is offline for the given store product variant
     */
    private function isStoreOffline(StoreProductVariant $storeProductVariant): bool
    {
        return $storeProductVariant->store && method_exists($storeProductVariant->store, 'isOffline') && $storeProductVariant->store->isOffline();
    }

    /**
     * Helper: Validate single-store checkout rule. Returns error response array on failure, null on success.
     */
    private function validateSingleStoreCheckout(?Cart $userCart, int $incomingStoreId): ?array
    {
        try {
            $settingService = app(SettingService::class);
            $settings = $settingService->getSettingByVariable(SettingTypeEnum::SYSTEM());
            $checkoutType = $settings->value['checkoutType'] ?? null;

            if ($checkoutType === 'single_store') {
                $existingStoreIds = collect($userCart?->items ?? [])->pluck('store_id')->filter()->unique();
                if ($existingStoreIds->count() > 0 && ! $existingStoreIds->contains($incomingStoreId)) {
                    return [
                        'success' => false,
                        'message' => __('labels.checkout_type_single_store_error'),
                        'data' => [],
                    ];
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Checkout type validation failed while adding to cart', [
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Helper: Extract quantity rules from product config with sane defaults.
     * Returns ['min' => int, 'step' => int, 'max' => int]
     */
    private function extractQuantityRules(StoreProductVariant $spv): array
    {
        $product = $spv->productVariant->product ?? null;

        return [
            'min' => (int) ($product->minimum_order_quantity ?? 1),
            'step' => (int) ($product->quantity_step_size ?? 1),
            'max' => (int) ($product->total_allowed_quantity ?? 0), // 0 = unlimited
        ];
    }

    /**
     * Helper: Validate quantity against rules. Returns error response array on failure, null on success.
     */
    private function validateQuantityRules(int $quantity, array $rules): ?array
    {
        $minQty = (int) ($rules['min'] ?? 1);
        $stepSize = (int) ($rules['step'] ?? 1);
        $maxTotal = (int) ($rules['max'] ?? 0);

        if ($stepSize > 1 && ($quantity % $stepSize) !== 0) {
            return [
                'success' => false,
                'message' => __('messages.quantity_must_be_multiple_of_step_size', ['step' => $stepSize]),
                'data' => [
                    'step_size' => $stepSize,
                    'attempted_quantity' => $quantity,
                ],
            ];
        }

        if ($quantity < $minQty) {
            return [
                'success' => false,
                'message' => __('messages.quantity_must_be_at_least_minimum_order_quantity', ['min' => $minQty]),
                'data' => [
                    'minimum_order_quantity' => $minQty,
                    'attempted_quantity' => $quantity,
                ],
            ];
        }

        if ($maxTotal > 0 && $quantity > $maxTotal) {
            return [
                'success' => false,
                'message' => __('messages.quantity_must_not_exceed_total_allowed_quantity', ['max' => $maxTotal]),
                'data' => [
                    'total_allowed_quantity' => $maxTotal,
                    'attempted_quantity' => $quantity,
                ],
            ];
        }

        return null;
    }

    /**
     * Helper: Ensure requested quantity is within available stock. Returns error array on failure, null on success.
     */
    private function checkStock(int $quantity, int $availableStock): ?array
    {
        if ($quantity > $availableStock) {
            return [
                'success' => false,
                'message' => __('messages.insufficient_stock_available'),
                'data' => ['available_stock' => $availableStock],
            ];
        }

        return null;
    }

    public static function validateCartMaxItems($cart): bool
    {
        $settingService = new SettingService;
        $setting = $settingService->getSettingByVariable(SettingTypeEnum::SYSTEM());
        $maxCartItems = $setting->value['maximumItemsAllowedInCart'] ?? 1;

        $itemCount = $cart->items->count() ?? 0;
        if ($itemCount > $maxCartItems) {
            return false;
        }

        return true;
    }

    public function syncMultiStoreCart(User $user, array $data): array
    {
        $synced = [];
        $failed = [];

        DB::beginTransaction();

        try {
            foreach ($data['items'] as $item) {
                // Optional per-item addon selections. Same shape as AddToCart:
                // [{addon_group_id, addon_item_id}, ...]. The resolve/validate
                // logic inside addToCart handles attachment + availability +
                // per-store stock checks — sync inherits all of that for free.
                $addons = is_array($item['addons'] ?? null) ? $item['addons'] : [];

                $result = $this->addToCart($user, [
                    'store_id' => $item['store_id'],
                    'product_variant_id' => $item['product_variant_id'],
                    'quantity' => $item['quantity'],
                    'addons' => $addons,
                    // For cart sync, we need to REPLACE existing quantity, not increment
                    'replace_quantity' => true,
                ]);

                // Load product resource for the provided store and variant
                $storeProductVariant = StoreProductVariant::with([
                    'productVariant.product.variants.storeProductVariants.store',
                    'productVariant.product.category',
                    'productVariant.product.brand',
                    'productVariant.product.seller.user',
                    'store',
                    'productVariant',
                ])
                    ->where('store_id', $item['store_id'])
                    ->where('product_variant_id', $item['product_variant_id'])
                    ->first();

                // Build ProductListResource from the linked product if available
                $productResource = null;
                if ($storeProductVariant && $storeProductVariant->productVariant && $storeProductVariant->productVariant->product) {
                    $product = $storeProductVariant->productVariant->product;
                    $productResource = new ProductListResource($product->loadMissing([
                        'variants.storeProductVariants.store',
                        'category',
                        'brand',
                        'seller.user',
                    ]));
                }

                if ($result['success']) {
                    $synced[] = [
                        'store_id' => $item['store_id'],
                        'product_variant_id' => $item['product_variant_id'],
                        'quantity' => $item['quantity'],
                        // Echo back the addons the client sent so they can
                        // reconcile per line without a second fetch.
                        'addons' => $addons,
                        'product' => $productResource,
                    ];
                } else {
                    $failed[] = [
                        'store_id' => $item['store_id'],
                        'product_variant_id' => $item['product_variant_id'],
                        'addons' => $addons,
                        'product' => $productResource,
                        'reason' => $result['message'],
                        // Addon-level failures (unavailable item, insufficient
                        // stock, detached attachment) put useful context in
                        // `result.data` — surface it so clients can pinpoint
                        // which addon broke the line.
                        'details' => $result['data'] ?? null,
                    ];
                }
            }

            DB::commit();

            return [
                'success' => true,
                'message' => __('labels.cart_synced_successfully'),
                'data' => [
                    'synced_items' => $synced,
                    'failed_items' => $failed,
                    'cart' => $this->getUserCart($user),
                ],
            ];
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('Multi-store cart sync failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => __('labels.something_went_wrong'),
                'data' => [],
            ];
        }
    }

    /**
     * Calculate cart item prices and totals.
     *
     * Addon selections snapshotted on `cart_item_addons` contribute to every
     * line: one `cart_item_addons` row represents "one unit of this addon per
     * parent cart item", so the per-line addon amount is
     * `quantity × sum(addon.price)`. This is folded into `items_total` so the
     * payment summary (delivery-free threshold, promo min_order_total, promo
     * discount, wallet debit, payable amount) reflects the true line total.
     *
     * Side effects on each cart item:
     *  - `price`         : product unit price × quantity + addon line total
     *  - `special_price` : product special-price × quantity + addon line total (when a special price is configured)
     *  - `addons_total`  : addon line total only (quantity × per-unit addon sum)
     *
     * @param  Cart  $cart  The cart to calculate totals for
     * @return array Array containing items_total and store_ids
     */
    private function calculateCartTotals(Cart $cart): array
    {
        $itemsTotal = 0;
        $storeIds = [];

        foreach ($cart->items as $key => $item) {
            if (empty($item->variant)) {
                $item->delete();

                continue;
            }

            if (! in_array($item->store_id, $storeIds)) {
                $storeIds[] = $item->store_id;
            }

            $storeVariant = $item->variant->storeProductVariants->where('store_id', $item->store->id)->first();

            // Per-unit addon cost for this line. Each `cart_item_addons` row
            // carries the store-level price snapshot taken when the addon was
            // attached — use that directly instead of re-resolving.
            $addonsPerUnit = $this->sumCartItemAddonPrices($item);
            $quantity = (int) $item->quantity;
            $addonsLineTotal = $quantity * $addonsPerUnit;

            $productLinePrice = $quantity * (float) $storeVariant->price;
            $productLineSpecialPrice = $quantity * (float) $storeVariant->special_price;

            $cart->items[$key]->price = $productLinePrice + $addonsLineTotal;
            $cart->items[$key]->special_price = $productLineSpecialPrice > 0
                ? $productLineSpecialPrice + $addonsLineTotal
                : $productLineSpecialPrice;
            $cart->items[$key]->addons_total = $addonsLineTotal;

            $effectiveUnitPrice = $storeVariant->special_price > 0
                ? (float) $storeVariant->special_price
                : (float) $storeVariant->price;

            $itemsTotal += ($quantity * $effectiveUnitPrice) + $addonsLineTotal;
        }

        $cart->items_total = $itemsTotal;

        return [
            'items_total' => $itemsTotal,
            'store_ids' => $storeIds,
        ];
    }

    /**
     * Sum the per-unit price snapshot of every addon attached to a cart line.
     * Uses the eager-loaded `addons` relation when available (the cart is
     * loaded with `items.addons.*` throughout this service) and falls back to
     * a lazy query so the method is safe to call in isolation.
     */
    private function sumCartItemAddonPrices(CartItem $item): float
    {
        if ($item->relationLoaded('addons')) {
            return (float) $item->getRelation('addons')->sum(fn ($a) => (float) $a->price);
        }

        return (float) $item->addons()->sum('price');
    }

    /**
     * Get user's cart with updated location information
     *
     * @param  User  $user  The user whose cart to retrieve
     * @param  float|null  $latitude  User's latitude coordinate
     * @param  float|null  $longitude  User's longitude coordinate
     * @param  bool  $isRushDelivery  Whether to use rush delivery
     * @param  bool  $useWallet  Whether to use wallet balance for payment
     * @param  string|null  $promoCode  Promo code to apply discount
     * @return array Cart data with success status and message
     */
    public function getCart(User $user, ?float $latitude = null, ?float $longitude = null, bool $isRushDelivery = false, bool $useWallet = false, ?string $promoCode = null, $addressId = null): array
    {
        try {
            DB::beginTransaction();

            // Get and validate user's cart
            $cart = $this->getUserCart($user);
            if ($cart->items->isEmpty()) {
                return [
                    'success' => false,
                    'message' => __('messages.cart_is_empty'),
                    'data' => [],
                ];
            }

            // Validate delivery zone and rush delivery if coordinates are provided
            if ($latitude !== null && $longitude !== null) {
                $zoneResult = $this->validateDeliveryZone($latitude, $longitude, $isRushDelivery);
                if (! $zoneResult['success']) {
                    return $zoneResult;
                }
                $zone = $zoneResult['zone'];
                $zone['rush_delivery_available'] = $zoneResult['rush_delivery_available'];
                if ($isRushDelivery && ! $zoneResult['rush_delivery_available']) {
                    $zone['rush_delivery_error_message'] = $zoneResult['message'];
                    // Force regular delivery when rush delivery is not available
                    $isRushDelivery = false;
                }

                // Check delivery availability and process cart items
                $processResult = $this->processCartItems($cart, $latitude, $longitude, $user);
                $removedItems = $processResult['removed_items'];

            } else {
                $zone = null;
                $removedItems = [];
            }
            if (empty($addressId)) {
                $latitude = null;
                $longitude = null;
                //                $isRushDelivery = false;
                //                $useWallet = false;
                //                $promoCode = null;
            }
            // Calculate payment summary
            $cart = $this->calculateCartPaymentSummary(cart: $cart, latitude: $latitude, longitude: $longitude, isRushDelivery: $isRushDelivery, useWallet: $useWallet, removedItems: $removedItems, user: $user, promoCode: $promoCode);
            DB::commit();

            // Prepare and return the response
            return $this->prepareCartResponse($cart, $removedItems, $zone);

        } catch (\Exception $e) {
            DB::rollBack();

            return [
                'success' => false,
                'message' => __('labels.something_went_wrong'),
                'data' => ['error' => $e->getMessage()],
            ];
        }
    }

    /**
     * Get and validate user's cart
     *
     * @param  User  $user  The user whose cart to retrieve
     * @return Cart Result containing success status and cart if found
     */
    public static function getUserCart(User $user): ?Cart
    {
        return Cart::with([
            'items' => function ($query) {
                $query->where('save_for_later', '0');
            },
            'items.product',
            'items.variant',
            'items.store',
            'items.addons.addonGroup',
            'items.addons.addonItem',
        ])
            ->where('user_id', $user->id)->first();
    }

    public function getSaveForLaterCart(User $user): Cart
    {
        return Cart::with([
            'items' => function ($query) {
                $query->where('save_for_later', '1');
            },
            'items.product',
            'items.variant',
            'items.store',
            'items.addons.addonGroup',
            'items.addons.addonItem',
        ])
            ->where('user_id', $user->id)->first();
    }

    public static function cartStoreCount(User $user): int
    {
        $cart = Cart::with([
            'items' => function ($query) {
                $query->where('save_for_later', '0');
                $query->groupBy('store_id');
            },
        ])
            ->where('user_id', $user->id)->first();

        return $cart->items->count() ?? 0;
    }

    /**
     * Validate delivery zone and rush delivery availability
     *
     * @param  float  $latitude  User's latitude coordinate
     * @param  float  $longitude  User's longitude coordinate
     * @param  bool  $isRushDelivery  Whether to use rush delivery
     * @return array Result containing success status and zone if valid
     */
    private function validateDeliveryZone(float $latitude, float $longitude, bool $isRushDelivery): array
    {
        $zone = DeliveryZoneService::getZonesAtPoint($latitude, $longitude);
        if (empty($zone) || ! $zone['exists']) {
            return [
                'success' => false,
                'message' => __('messages.invalid_coordinates'),
                'parameters' => [
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'is_rush_delivery' => $isRushDelivery,
                ],
                'rush_delivery_available' => false,
                'zone' => null,
            ];
        }

        $rushDeliveryAvailable = $this->isRushDeliveryAvailable($zone);

        if ($isRushDelivery && ! $rushDeliveryAvailable) {
            return [
                'success' => true,
                'message' => __('labels.rush_delivery_not_available_for_this_zone'),
                'parameters' => [
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'is_rush_delivery' => $isRushDelivery,
                    'zone_id' => $zone['id'] ?? null,
                    'zone_name' => $zone['name'] ?? null,
                ],
                'rush_delivery_available' => false,
                'zone' => $zone,
            ];
        }

        return [
            'success' => true,
            'message' => __('labels.valid_delivery_zone'),
            'parameters' => [
                'latitude' => $latitude,
                'longitude' => $longitude,
                'is_rush_delivery' => $isRushDelivery,
                'zone_id' => $zone['id'] ?? null,
                'zone_name' => $zone['name'] ?? null,
            ],
            'rush_delivery_available' => $rushDeliveryAvailable,
            'zone' => $zone,
        ];
    }

    /**
     * Process cart items based on delivery availability
     *
     * @param  Cart  $cart  The cart to process
     * @param  float  $latitude  User's latitude coordinate
     * @param  float  $longitude  User's longitude coordinate
     * @return array Result containing processed cart and removed items
     */
    private function processCartItems(Cart $cart, float $latitude, float $longitude, $user): array
    {
        // Check delivery availability and remove unavailable items
        $availabilityResult = DeliveryZoneService::checkDeliveryAvailability($cart, $latitude, $longitude);
        $removedItems = $availabilityResult['removed_items'];
        $reassignedItems = $availabilityResult['reassigned_items'];

        // Reload the cart with remaining items
        $cart = $this->getUserCart($user);

        return [
            'cart' => $cart,
            'removed_items' => $removedItems,
            'reassigned_items' => $reassignedItems,
        ];
    }

    /**
     * Calculate payment summary for the cart
     *
     * @param  Cart  $cart  The cart to calculate payment for
     * @param  bool  $isRushDelivery  Whether to use rush delivery
     * @param  bool  $useWallet  Whether to use wallet balance for payment
     * @param  array  $removedItems  Items removed from the cart due to availability
     * @param  User  $user  The user who owns the cart
     * @param  float|null  $latitude  User's latitude coordinate
     * @param  float|null  $longitude  User's longitude coordinate
     * @param  string|null  $promoCode  Promo code to apply discount
     * @return Cart Cart with payment summary attached
     */
    private function calculateCartPaymentSummary(Cart $cart, bool $isRushDelivery, bool $useWallet, array $removedItems, User $user, ?float $latitude = null, ?float $longitude = null, ?string $promoCode = null): Cart
    {
        try {
            if ($latitude !== null && $longitude !== null) {

                // Get all the payment-related summary
                $paymentSummary = $this->getPaymentSummary(
                    cart: $cart,
                    latitude: $latitude,
                    longitude: $longitude,
                    isRushDelivery: $isRushDelivery,
                    useWallet: $useWallet,
                    promoCode: $promoCode
                );
                $cart->payment_summary = $paymentSummary;
                // Fire event
                event(new CartUpdatedByLocation($cart, $removedItems, $user, $latitude, $longitude));
            } else {
                $cart->payment_summary = $this->createDefaultPaymentSummary($cart, $isRushDelivery, $useWallet);
            }

        } catch (\Exception $e) {
            Log::error('Error getting payment summary: '.$e->getMessage());
            // Set empty payment summary to avoid null reference
            $cart->payment_summary = $this->createDefaultPaymentSummary($cart, $isRushDelivery, $useWallet);
        }

        return $cart;
    }

    /**
     * Prepare the cart response
     *
     * @param  Cart  $cart  The cart to include in the response
     * @param  array  $removedItems  Items removed from cart due to availability
     * @param  array|null  $zone  Delivery zone information
     * @return array Response with cart data
     */
    private function prepareCartResponse(Cart $cart, array $removedItems, ?array $zone): array
    {
        return [
            'success' => true,
            'message' => count($removedItems) > 0 ? __('messages.cart_updated_based_on_location') : __('messages.cart_location_verified'),
            'data' => [
                'cart' => $cart,
                'removed_items' => $removedItems,
                'removed_count' => count($removedItems),
                'delivery_zone' => $zone,
            ],
        ];
    }

    /**
     * Calculate payment summary for a cart
     *
     * @param  Cart  $cart  The cart to calculate payment summary for
     * @param  float  $latitude  User's latitude coordinate
     * @param  float  $longitude  User's longitude coordinate
     * @param  bool  $isRushDelivery  Whether to use rush delivery
     * @param  bool  $useWallet  Whether to use wallet balance for payment
     * @param  string|null  $promoCode  Promo code to apply discount
     * @return array Payment summary details
     */
    public function getPaymentSummary(Cart $cart, float $latitude, float $longitude, bool $isRushDelivery = false, bool $useWallet = false, ?string $promoCode = null): array
    {
        try {
            $zone = DeliveryZoneService::getZonesAtPoint($latitude, $longitude);
            $isRushDeliveryAvailable = $this->isRushDeliveryAvailable($zone);

            // If rush delivery is requested but not available, use regular delivery
            if ($isRushDelivery && ! $isRushDeliveryAvailable) {
                $isRushDelivery = false;
            }

            $this->validateZoneData($zone, $isRushDelivery);

            $totalsResult = $this->calculateCartTotals($cart);
            $itemsTotal = $totalsResult['items_total'];
            $storeIds = $totalsResult['store_ids'];
            $totalStores = count($storeIds);

            $perStoreDropOffFee = $zone['per_store_drop_off_fee'] * $totalStores;
            $handlingCharges = $zone['handling_charges'];

            // Calculate delivery distance information
            $distanceInfo = $this->calculateDeliveryDistanceInfo($storeIds, $zone, $latitude, $longitude);
            $deliveryDistanceKm = $distanceInfo['distance_km'];
            $deliveryDistanceCharges = $distanceInfo['distance_charges'];

            $regularDeliveryCharge = $this->calculateAppliedDeliveryCharge(
                itemsTotal: $itemsTotal,
                baseDeliveryCharge: (float) $zone['regular_delivery_charges'],
                deliveryDistanceCharges: $deliveryDistanceCharges,
                freeDeliveryAmount: (float) $zone['free_delivery_amount']
            );

            $rushDeliveryCharge = $isRushDeliveryAvailable
                ? $this->calculateAppliedDeliveryCharge(
                    itemsTotal: $itemsTotal,
                    baseDeliveryCharge: (float) $zone['rush_delivery_charges'],
                    deliveryDistanceCharges: $deliveryDistanceCharges,
                    freeDeliveryAmount: (float) $zone['free_delivery_amount'],
                    isRushDelivery: true
                )
                : 0;

            // Determine which delivery charges to use based on rush delivery flag
            $deliveryCharges = $isRushDelivery
                ? $zone['rush_delivery_charges']
                : $zone['regular_delivery_charges'];

            // Calculate delivery charges
            $totalDeliveryCharges = $isRushDelivery ? $rushDeliveryCharge : $regularDeliveryCharge;

            // Calculate payable amount
            $payableAmount = $itemsTotal + $handlingCharges + $totalDeliveryCharges + $perStoreDropOffFee;

            // Apply promo code discount if provided
            $promoDiscount = 0;
            $promoCodeApplied = null;
            $promoValidationError = null;

            if (! empty($promoCode)) {
                $promoResult = $this->validateAndApplyPromoCode(promoCode: $promoCode, user: $cart->user, cartTotal: $itemsTotal, deliveryCharge: $totalDeliveryCharges);
                if ($promoResult['success']) {
                    $promoDiscount = $promoResult['discount'];
                    $promoCodeApplied = $promoResult['promo'];
                    if ($promoCodeApplied['promo_mode'] === PromoModeEnum::INSTANT()) {
                        $payableAmount = max(0, $payableAmount - $promoDiscount);
                    }
                } else {
                    $promoValidationError = $promoResult['message'];
                }
            }

            // Calculate estimated delivery time
            $estimatedDeliveryTime = $this->calculateEstimatedDeliveryTime(
                $cart,
                $zone,
                $deliveryDistanceKm,
                $isRushDelivery
            );

            // Get wallet balance if using wallet
            $walletAmountUsed = 0;
            $orderTotal = $payableAmount;
            $wallet = $cart->user->wallet()->first();
            $walletBalance = ! empty($wallet) ? $wallet->balance : 0;
            if ($useWallet) {
                if ($wallet) {
                    $walletAmountUsed = min($walletBalance, $payableAmount);
                    $payableAmount = $payableAmount - $walletAmountUsed;
                }
            }

            return [
                'items_total' => (float) $itemsTotal,
                'per_store_drop_off_fee' => (float) $perStoreDropOffFee,
                'is_rush_delivery' => $isRushDelivery,
                'is_rush_delivery_available' => $isRushDeliveryAvailable,
                'delivery_charges' => (float) $deliveryCharges,
                'regular_delivery_charge' => (float) $regularDeliveryCharge,
                'rush_delivery_charge' => (float) $rushDeliveryCharge,
                'handling_charges' => (float) $handlingCharges,
                'delivery_distance_charges' => (float) $deliveryDistanceCharges,
                'delivery_distance_km' => (float) $deliveryDistanceKm,
                'total_stores' => (float) $totalStores,
                'total_delivery_charges' => (float) $totalDeliveryCharges,
                'estimated_delivery_time' => (float) $estimatedDeliveryTime,
                'promo_code' => $promoCode,
                'promo_discount' => (float) $promoDiscount,
                'promo_applied' => $promoCodeApplied,
                'promo_error' => $promoValidationError,
                'use_wallet' => $useWallet,
                'wallet_balance' => (float) $walletBalance,
                'wallet_amount_used' => (float) $walletAmountUsed,
                'payable_amount' => (float) $payableAmount,
                'order_total' => (float) $orderTotal,
            ];
        } catch (\Exception $e) {
            Log::error('Error calculating payment summary: '.$e->getMessage());

            return $this->createDefaultPaymentSummary($cart, $isRushDelivery, $useWallet);
        }
    }

    /**
     * Validate required zone data
     *
     * @param  array  $zone  Delivery zone information
     * @param  bool  $isRushDelivery  Whether to validate rush delivery data
     *
     * @throws \Exception If required zone data is missing
     */
    private function validateZoneData(array $zone, bool $isRushDelivery = false): void
    {
        // Validate basic required fields
        if (
            ! isset($zone['per_store_drop_off_fee']) ||
            ! isset($zone['regular_delivery_charges']) ||
            ! isset($zone['handling_charges']) ||
            ! isset($zone['free_delivery_amount'])
        ) {
            throw new \Exception('Missing required delivery zone data');
        }

        // If rush delivery is requested, validate rush delivery fields
        // Note: We no longer throw exceptions for rush delivery unavailability
        // as we handle this gracefully by falling back to regular delivery
        if ($isRushDelivery) {
            if (! isset($zone['rush_delivery_charges']) || ! isset($zone['rush_delivery_time_per_km'])) {
                throw new \Exception('Missing required rush delivery data');
            }
        }
    }

    /**
     * Calculate delivery distance information
     *
     * @param  array  $storeIds  Array of store IDs
     * @param  array  $zone  Delivery zone information
     * @param  float  $latitude  User's latitude coordinate
     * @param  float  $longitude  User's longitude coordinate
     * @return array Distance information including distance_km and distance_charges
     */
    private function calculateDeliveryDistanceInfo(array $storeIds, array $zone, float $latitude, float $longitude): array
    {
        $deliveryDistanceKm = 0;
        $deliveryDistanceCharges = 0;

        if (! empty($storeIds)) {
            try {
                $routeInfo = DeliveryZoneService::calculateDeliveryRoute($latitude, $longitude, $storeIds);

                // Add delivery distance-based charges if applicable
                if (
                    isset($routeInfo['total_distance']) &&
                    $routeInfo['total_distance'] > 0 &&
                    isset($zone['distance_based_delivery_charges'])
                ) {
                    $deliveryDistanceKm = $routeInfo['total_distance'];
                    $deliveryDistanceCharges = $zone['distance_based_delivery_charges'] * $routeInfo['total_distance'];
                }
            } catch (\Exception $e) {
                // Log the error but continue with calculation
                Log::error('Error calculating delivery route: '.$e->getMessage());
            }
        }

        return [
            'distance_km' => $deliveryDistanceKm,
            'distance_charges' => $deliveryDistanceCharges,
        ];
    }

    /**
     * Calculate the applied delivery charge for a delivery mode.
     */
    private function calculateAppliedDeliveryCharge(
        float $itemsTotal,
        float $baseDeliveryCharge,
        float $deliveryDistanceCharges,
        float $freeDeliveryAmount,
        bool $isRushDelivery = false
    ): float {
        if (! $isRushDelivery && $itemsTotal >= $freeDeliveryAmount) {
            return 0;
        }

        return $baseDeliveryCharge + $deliveryDistanceCharges;
    }

    /**
     * Calculate estimated delivery time
     *
     * @param  Cart  $cart  The cart to calculate delivery time for
     * @param  array  $zone  Delivery zone information
     * @param  float  $deliveryDistanceKm  Delivery distance in kilometers
     * @param  bool  $isRushDelivery  Whether to use rush delivery time
     * @return int Estimated delivery time in minutes
     */
    private function calculateEstimatedDeliveryTime(Cart $cart, array $zone, float $deliveryDistanceKm, bool $isRushDelivery = false): int
    {
        if ($deliveryDistanceKm <= 0) {
            return 0;
        }

        // Find maximum base preparation time from all products
        $maxBasePrepTime = 0;
        foreach ($cart->items as $item) {
            $product = $item->product;
            if ($product && $product->base_prep_time > $maxBasePrepTime) {
                $maxBasePrepTime = $product->base_prep_time;
            }
        }

        // Determine which delivery time per km to use based on rush delivery flag
        $deliveryTimePerKm = $isRushDelivery && isset($zone['rush_delivery_time_per_km'])
            ? $zone['rush_delivery_time_per_km']
            : ($zone['delivery_time_per_km'] ?? 0);

        $bufferTime = $zone['buffer_time'] ?? 0;

        // Calculate estimated time using the formula
        $estimatedTime = $maxBasePrepTime + ($deliveryDistanceKm * $deliveryTimePerKm) + $bufferTime;

        // Round to the nearest minute
        return ceil($estimatedTime);
    }

    /**
     * Create a default payment summary with zeros
     *
     * @param  Cart  $cart  The cart to create default summary for
     * @param  bool  $isRushDelivery  Whether this is a rush delivery
     * @param  bool  $useWallet  Whether to use wallet balance for payment
     * @return array Default payment summary
     */
    private function createDefaultPaymentSummary(Cart $cart, bool $isRushDelivery = false, bool $useWallet = false): array
    {
        $totalsResult = $this->calculateCartTotals($cart);
        $itemsTotal = $totalsResult['items_total'] ?? 0;
        $walletBalance = 0;
        $walletAmountUsed = 0;
        $remainingPayable = $itemsTotal;
        $orderTotal = $remainingPayable;
        $wallet = $cart->user->wallet()->first();
        $walletBalance = ! empty($wallet) ? $wallet->balance : 0;
        if ($useWallet && $cart->user) {
            if ($wallet) {
                $walletAmountUsed = min($walletBalance, $itemsTotal);
                $remainingPayable = $itemsTotal - $walletAmountUsed;
            }
        }

        return [
            'items_total' => $itemsTotal,
            'per_store_drop_off_fee' => 0,
            'is_rush_delivery' => $isRushDelivery,
            'is_rush_delivery_available' => false,
            'delivery_charges' => 0,
            'regular_delivery_charge' => 0,
            'rush_delivery_charge' => 0,
            'handling_charges' => 0,
            'delivery_distance_charges' => 0,
            'delivery_distance_km' => 0,
            'total_stores' => 0,
            'total_delivery_charges' => 0,
            'estimated_delivery_time' => 0,
            'use_wallet' => $useWallet,
            'wallet_balance' => $walletBalance,
            'wallet_amount_used' => $walletAmountUsed,
            'payable_amount' => $remainingPayable,
            'order_total' => (float) $orderTotal,
        ];
    }

    /**
     * Remove item from the cart
     */
    public function removeFromCart(User $user, int $cartItemId): array
    {
        try {
            DB::beginTransaction();

            $cartItem = CartItem::whereHas('cart', function ($query) use ($user) {
                $query->where('user_id', $user->id);
            })->find($cartItemId);

            if (! $cartItem) {
                return [
                    'success' => false,
                    'message' => __('messages.cart_item_not_found'),
                    'data' => [],
                ];
            }

            // Load relationships before deletion
            $cartItem->load(['product', 'variant', 'store']);
            $cart = $cartItem->cart;

            // Delete the item
            $cartItem->delete();

            // Fire event
            event(new ItemRemovedFromCart($cart, $cartItem, $user));

            // Get updated cart
            $cart = $this->getUserCart($user);

            DB::commit();

            return [
                'success' => true,
                'message' => __('messages.item_removed_from_cart_successfully'),
                'data' => $cart,
            ];

        } catch (\Exception $e) {
            DB::rollBack();

            return [
                'success' => false,
                'message' => __('labels.something_went_wrong'),
                'data' => ['error' => $e->getMessage()],
            ];
        }
    }

    /**
     * Save cart item for later
     */
    public function addToSaveForLater(User $user, int $cartItemId): array
    {
        try {
            DB::beginTransaction();

            $cartItem = CartItem::whereHas('cart', function ($query) use ($user) {
                $query->where('user_id', $user->id);
            })->find($cartItemId);

            if (! $cartItem) {
                return [
                    'success' => false,
                    'message' => __('messages.cart_item_not_found'),
                    'data' => [],
                ];
            }

            // Load relationships before deletion
            $cartItem->load(['product', 'variant', 'store']);
            $cart = $cartItem->cart;

            // Delete the item
            $cartItem->update(['save_for_later' => true]);

            // Fire event
            event(new ItemRemovedFromCart($cart, $cartItem, $user));

            // Get updated cart
            $cart = $this->getSaveForLaterCart($user);

            DB::commit();

            return [
                'success' => true,
                'message' => __('labels.item_saved_for_later_successfully'),
                'data' => $cart,
            ];

        } catch (\Exception $e) {
            DB::rollBack();

            return [
                'success' => false,
                'message' => __('labels.something_went_wrong'),
                'data' => ['error' => $e->getMessage()],
            ];
        }
    }

    /**
     * Update cart item quantity (and, optionally, its addon selections).
     *
     * `$addons` semantics:
     *  - `null`  → leave existing addons untouched
     *  - `[]`    → clear all addons on the line (signature goes back to NULL)
     *  - `[...]` → replace the entire set; validates against variant attachments
     *              and recomputes the line's `addon_signature`.
     *
     * @param  array<int,array{addon_group_id:int|string,addon_item_id:int|string}>|null  $addons
     */
    public function updateCartItemQuantity(User $user, int $cartItemId, int $quantity, ?array $addons = null): array
    {
        try {
            DB::beginTransaction();

            $cartItem = CartItem::whereHas('cart', function ($query) use ($user) {
                $query->where('user_id', $user->id);
            })->find($cartItemId);

            if (! $cartItem) {
                return [
                    'success' => false,
                    'message' => __('messages.cart_item_not_found'),
                    'data' => [],
                ];
            }

            // Check stock availability
            $storeProductVariant = StoreProductVariant::where('store_id', $cartItem->store_id)
                ->where('product_variant_id', $cartItem->product_variant_id)
                ->first();

            if (! $storeProductVariant || $quantity > $storeProductVariant->stock) {
                return [
                    'success' => false,
                    'message' => __('messages.insufficient_stock_available'),
                    'data' => ['available_stock' => $storeProductVariant->stock ?? 0],
                ];
            }

            // When the caller explicitly sends an addons payload, resolve +
            // replace. Leaving `$addons === null` keeps the current selections
            // but we still re-check stock for them against the new quantity.
            if ($addons !== null) {
                $addonResolution = $this->resolveAddonSelections(
                    storeId: (int) $cartItem->store_id,
                    productVariantId: (int) $cartItem->product_variant_id,
                    addons: $addons,
                );
                if (! $addonResolution['success']) {
                    DB::rollBack();

                    return $addonResolution['error'];
                }
                $resolvedAddons = $addonResolution['resolved'];

                if ($error = $this->checkAddonsStock($resolvedAddons, $quantity)) {
                    DB::rollBack();

                    return $error;
                }

                $newSignature = CartItem::buildAddonSignature(
                    array_column($resolvedAddons, 'addon_item_id')
                );

                $this->persistCartItemAddons($cartItem, $resolvedAddons);

                $cartItem->update([
                    'quantity' => $quantity,
                    'addon_signature' => $newSignature,
                ]);
            } else {
                // No addon changes — still validate that the existing addon
                // selections have enough per-store stock for the new quantity.
                if ($error = $this->checkExistingAddonsStock($cartItem, $quantity)) {
                    DB::rollBack();

                    return $error;
                }
                $cartItem->update(['quantity' => $quantity]);
            }

            // Get updated cart
            $cart = $this->getUserCart($user);

            DB::commit();

            return [
                'success' => true,
                'message' => __('messages.cart_item_quantity_updated_successfully'),
                'data' => $cart,
            ];

        } catch (\Exception $e) {
            DB::rollBack();

            return [
                'success' => false,
                'message' => __('labels.something_went_wrong'),
                'data' => ['error' => $e->getMessage()],
            ];
        }
    }

    /**
     * Clear entire cart
     */
    public function clearCart(User $user): array
    {
        try {
            DB::beginTransaction();

            $cart = $this->getUserCart($user);

            if (! $cart) {
                return [
                    'success' => true,
                    'message' => __('messages.cart_is_empty'),
                    'data' => [],
                ];
            }

            // Delete all cart items
            $cart->items()->where('save_for_later', '0')->delete();

            DB::commit();

            return [
                'success' => true,
                'message' => __('messages.cart_cleared_successfully'),
                'data' => [],
            ];

        } catch (\Exception $e) {
            DB::rollBack();

            return [
                'success' => false,
                'message' => __('labels.something_went_wrong'),
                'data' => ['error' => $e->getMessage()],
            ];
        }
    }

    /**
     * Update cart based on user location
     *
     * @param  User  $user  The user whose cart to update
     * @param  float  $latitude  User's latitude coordinate
     * @param  float  $longitude  User's longitude coordinate
     * @param  bool  $isRushDelivery  Whether to use rush delivery
     * @param  bool  $useWallet  Whether to use wallet balance for payment
     * @return array Cart data with success status and message
     */
    public function updateCartByLocation(User $user, float $latitude, float $longitude, bool $isRushDelivery = false, bool $useWallet = false): array
    {
        // This method is essentially the same as getCart but specifically for location updates
        // We can reuse all the helper methods we created for getCart
        return $this->getCart($user, $latitude, $longitude, $isRushDelivery, $useWallet);
    }

    /**
     * Check if rush delivery is available in the given zone
     *
     * @param  array  $zone  Delivery zone information
     * @return bool True if rush delivery is available, false otherwise
     */
    private function isRushDeliveryAvailable(array $zone): bool
    {
        return isset($zone['rush_delivery_enabled']) &&
            $zone['rush_delivery_enabled'] &&
            isset($zone['rush_delivery_charges']) &&
            isset($zone['rush_delivery_time_per_km']);
    }

    /**
     * Public method to validate promo code
     *
     * @param  string  $promoCode  The promo code to validate
     * @param  User  $user  The user applying the promo code
     * @param  float  $cartTotal  The order amount before discount
     * @return array Result with success status, discount amount, and promo details
     */
    public function validatePromoCode(string $promoCode, User $user, float $cartTotal, float $deliveryCharge): array
    {
        return $this->validateAndApplyPromoCode($promoCode, $user, $cartTotal, $deliveryCharge);
    }

    /**
     * Validate and apply promo code discount
     *
     * @param  string  $promoCode  The promo code to validate
     * @param  User  $user  The user applying the promo code
     * @param  float  $cartTotal  The order amount before discount
     * @return array Result with success status, discount amount, and promo details
     */
    private function validateAndApplyPromoCode(string $promoCode, User $user, float $cartTotal, float $deliveryCharge): array
    {
        try {
            // Find the promo code
            $promo = Promo::where('code', $promoCode)->first();

            if (! $promo) {
                return [
                    'success' => false,
                    'message' => __('messages.invalid_promo_code'),
                    'discount' => 0,
                    'promo' => null,
                ];
            }

            // Check if promo code is active (not soft deleted)
            if ($promo->deleted_at) {
                return [
                    'success' => false,
                    'message' => __('messages.promo_code_expired'),
                    'discount' => 0,
                    'promo' => null,
                ];
            }

            // Check date validity
            $now = now();
            if ($promo->start_date && $now->lt($promo->start_date)) {
                return [
                    'success' => false,
                    'message' => __('messages.promo_code_not_yet_active'),
                    'discount' => 0,
                    'promo' => null,
                ];
            }

            if ($promo->end_date && $now->gt($promo->end_date)) {
                return [
                    'success' => false,
                    'message' => __('messages.promo_code_expired'),
                    'discount' => 0,
                    'promo' => null,
                ];
            }

            // Check minimum order total
            if ($promo->min_order_total && $cartTotal < $promo->min_order_total) {
                return [
                    'success' => false,
                    'message' => __('messages.minimum_order_amount_not_met', ['amount' => $promo->min_order_total]),
                    'discount' => 0,
                    'promo' => null,
                ];
            }

            // Check maximum total usage
            if ($promo->max_total_usage && $promo->usage_count >= $promo->max_total_usage) {
                return [
                    'success' => false,
                    'message' => __('messages.promo_code_usage_limit_exceeded'),
                    'discount' => 0,
                    'promo' => null,
                ];
            }

            // Check maximum usage per user
            if ($promo->max_usage_per_user) {
                $userUsageCount = OrderPromoLine::where('promo_id', $promo->id)
                    ->whereHas('order', function ($query) use ($user) {
                        $query->where('user_id', $user->id);
                    })
                    ->count();

                if ($userUsageCount >= $promo->max_usage_per_user) {
                    return [
                        'success' => false,
                        'message' => __('messages.promo_code_user_limit_exceeded'),
                        'discount' => 0,
                        'promo' => null,
                    ];
                }
            }

            // Calculate discount
            $discount = 0;
            if ($promo->discount_type === PromoDiscountTypeEnum::PERCENTAGE()) {
                $discount = ($cartTotal * $promo->discount_amount) / 100;
                if ($promo->max_discount_value && $discount > $promo->max_discount_value) {
                    $discount = $promo->max_discount_value;
                }
            } elseif ($promo->discount_type === PromoDiscountTypeEnum::FIXED()) {
                $discount = $promo->discount_amount;
            } elseif ($promo->discount_type === PromoDiscountTypeEnum::FREE_SHIPPING()) {
                $discount = $deliveryCharge;
            }

            // Ensure discount doesn't exceed order amount
            $discount = min($discount, $cartTotal);

            return [
                'success' => true,
                'message' => __('messages.promo_code_applied_successfully'),
                'discount' => $discount,
                'promo' => $promo,
            ];

        } catch (\Exception $e) {
            Log::error('Error validating promo code: '.$e->getMessage());

            return [
                'success' => false,
                'message' => __('messages.promo_code_validation_error'),
                'discount' => 0,
                'promo' => null,
            ];
        }
    }

    /**
     * Reorder: Add all items from a past order back into the user's cart.
     *
     * Quantity logic: Always add using the product's quantity_step_size (default 1). Any
     * provided quantities or previous order quantities are ignored as per requirement.
     *
     * @param  User  $user  The authenticated user requesting reorder
     * @param  int  $orderId  The ID of the order to reorder
     * @param  array|null  $quantities  Ignored. Kept for backward compatibility.
     * @return array Standard service response with per-item results
     */
    public function reorderFromOrder(User $user, int $orderId): array
    {
        // dd('here in service');
        try {
            $order = Order::with(['items.product'])
                ->where('id', $orderId)
                ->where('user_id', $user->id)
                ->first();
            if (! $order) {
                return [
                    'success' => false,
                    'message' => __('labels.order_not_found_or_not_yours') ?? __('messages.order_not_found'),
                    'data' => [],
                ];
            }

            if ($order->items->isEmpty()) {
                return [
                    'success' => false,
                    'message' => __('messages.no_items_found') ?? 'No items found in the order.',
                    'data' => [],
                ];
            }

            $results = [];
            $successCount = 0;
            $failureCount = 0;

            foreach ($order->items as $item) {
                // Determine target quantity strictly from product's step size
                $stepSize = (int) ($item->product->quantity_step_size ?? 1);
                $desiredQty = max(1, $stepSize);

                $addRes = $this->addToCart($user, [
                    'store_id' => $item->store_id,
                    'product_variant_id' => $item->product_variant_id,
                    'quantity' => $desiredQty,
                    // do not force replace; let addToCart handle incrementing if already present
                    'replace_quantity' => false,
                ]);

                $results[] = [
                    'order_item_id' => $item->id,
                    'store_id' => $item->store_id,
                    'product_variant_id' => $item->product_variant_id,
                    'requested_quantity' => $desiredQty,
                    'success' => $addRes['success'] ?? false,
                    'message' => $addRes['message'] ?? null,
                    'data' => $addRes['data'] ?? [],
                ];

                if (! empty($addRes['success'])) {
                    $successCount++;
                } else {
                    $failureCount++;
                }
            }

            $overallSuccess = $successCount > 0 && $failureCount === 0;
            $partial = $successCount > 0 && $failureCount > 0;

            $message = __('labels.success');
            if ($partial) {
                $message = __('labels.partial_success') ?? 'Some items could not be added to the cart.';
            } elseif ($successCount === 0) {
                $message = __('labels.failed_to_add_items_to_cart') ?? 'Failed to add items to cart.';
            }

            return [
                'success' => $overallSuccess || $partial,
                'message' => $message,
                'data' => [
                    'added' => $successCount,
                    'failed' => $failureCount,
                    'items' => $results,
                ],
            ];
        } catch (\Throwable $e) {
            Log::error('Reorder failed', [
                'order_id' => $orderId,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            dd('here');

            return [
                'success' => false,
                'message' => __('messages.something_went_wrong') ?? 'Something went wrong',
                'data' => ['error' => $e->getMessage()],
            ];
        }
    }
}
