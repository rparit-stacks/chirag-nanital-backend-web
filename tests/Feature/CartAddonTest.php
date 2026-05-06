<?php

use App\Enums\Addon\AddonGroupSelectionTypeEnum;
use App\Enums\Addon\AddonGroupStatusEnum;
use App\Enums\GuardNameEnum;
use App\Models\AddonGroup;
use App\Models\AddonItem;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\CartItemAddon;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Seller;
use App\Models\Store;
use App\Models\StoreAddonItem;
use App\Models\StoreProductVariant;
use App\Models\StoreProductVariantAddon;
use App\Models\User;
use App\Services\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

/**
 * Feature tests for the cart's addon selection pathway.
 *
 * These tests exercise `CartService::addToCart` and
 * `CartService::updateCartItemQuantity` directly — the goal is to validate
 * the line-matching, signature, replacement, and validation rules without
 * pulling the HTTP layer in. Events are faked so that listeners
 * (`UpdateInventoryTracking`, `LogCartActivity`) don't fire side effects.
 */
beforeEach(function () {
    // Listeners on cart events dispatch unrelated work — silence them so the
    // tests remain narrowly scoped to cart/addon behaviour.
    Event::fake();

    $this->service = app(CartService::class);

    $this->user = User::forceCreate([
        'name'         => 'Cart Tester',
        'email'        => 'cart.addon.'.uniqid().'@example.test',
        'password'     => bcrypt('secret'),
        'access_panel' => GuardNameEnum::WEB->value,
    ]);

    $this->seller = Seller::factory()->create();

    $this->store = Store::forceCreate([
        'seller_id' => $this->seller->id,
        'name'      => 'Downtown',
    ]);

    $this->product = Product::forceCreate([
        'seller_id' => $this->seller->id,
        'title'     => 'Pizza',
    ]);

    $this->variant = ProductVariant::forceCreate([
        'product_id' => $this->product->id,
        'title'      => '14 inch',
    ]);

    StoreProductVariant::forceCreate([
        'product_variant_id' => $this->variant->id,
        'store_id'           => $this->store->id,
        'sku'                => 'SKU-PIZZA-14',
        'price'              => 10,
        'special_price'      => 10,
        'cost'               => 5,
        'stock'              => 100,
    ]);

    // Multi-select "Toppings" group.
    $this->toppings = AddonGroup::create([
        'seller_id'      => $this->seller->id,
        'title'          => 'Toppings',
        'selection_type' => AddonGroupSelectionTypeEnum::MULTIPLE->value,
        'is_required'    => false,
        'status'         => AddonGroupStatusEnum::ACTIVE->value,
    ]);

    $this->cheese = AddonItem::create([
        'addon_group_id' => $this->toppings->id,
        'title'          => 'Extra cheese',
        'price'          => 1.50,
        'is_available'   => true,
        'status'         => AddonGroupStatusEnum::ACTIVE->value,
    ]);
    $this->pepperoni = AddonItem::create([
        'addon_group_id' => $this->toppings->id,
        'title'          => 'Pepperoni',
        'price'          => 2.00,
        'is_available'   => true,
        'status'         => AddonGroupStatusEnum::ACTIVE->value,
    ]);

    // Attach both items to the variant at this store — authoritative
    // `store_product_variant_addons` rows the cart will validate against.
    foreach ([$this->cheese, $this->pepperoni] as $item) {
        StoreProductVariantAddon::create([
            'store_id'           => $this->store->id,
            'product_variant_id' => $this->variant->id,
            'addon_group_id'     => $this->toppings->id,
            'addon_item_id'      => $item->id,
        ]);

        // Per-store snapshot row — signals "available" and provides price.
        StoreAddonItem::factory()->create([
            'store_id'      => $this->store->id,
            'addon_item_id' => $item->id,
            'price'         => $item->price,
            'cost'          => 0.50,
            'stock'         => 50,
            'is_available'  => true,
        ]);
    }
});

it('creates a new cart line with addons and snapshots the signature', function () {
    $result = $this->service->addToCart($this->user, [
        'store_id'           => $this->store->id,
        'product_variant_id' => $this->variant->id,
        'quantity'           => 1,
        'addons' => [
            ['addon_group_id' => $this->toppings->id, 'addon_item_id' => $this->cheese->id],
            ['addon_group_id' => $this->toppings->id, 'addon_item_id' => $this->pepperoni->id],
        ],
    ]);

    expect($result['success'])->toBeTrue();

    $cart = Cart::where('user_id', $this->user->id)->firstOrFail();
    $lines = CartItem::where('cart_id', $cart->id)->get();

    expect($lines)->toHaveCount(1)
        ->and((int) $lines->first()->quantity)->toBe(1)
        ->and($lines->first()->addon_signature)->not->toBeNull();

    // Both selected addons should be snapshotted against the line.
    $addonRows = CartItemAddon::where('cart_item_id', $lines->first()->id)->get();
    expect($addonRows)->toHaveCount(2)
        ->and($addonRows->pluck('addon_item_id')->sort()->values()->all())
        ->toEqual(collect([$this->cheese->id, $this->pepperoni->id])->sort()->values()->all());
});

it('merges quantities when the same addons are added again', function () {
    $payload = [
        'store_id'           => $this->store->id,
        'product_variant_id' => $this->variant->id,
        'quantity'           => 1,
        'addons' => [
            ['addon_group_id' => $this->toppings->id, 'addon_item_id' => $this->cheese->id],
        ],
    ];

    $this->service->addToCart($this->user, $payload);
    // Second call with an identical set — same signature → quantity increments.
    $result = $this->service->addToCart($this->user, $payload);

    expect($result['success'])->toBeTrue();

    $cart  = Cart::where('user_id', $this->user->id)->firstOrFail();
    $lines = CartItem::where('cart_id', $cart->id)->get();

    expect($lines)->toHaveCount(1)
        ->and((int) $lines->first()->quantity)->toBe(2);

    // Addon rows remain a single snapshot for the merged line.
    expect(CartItemAddon::where('cart_item_id', $lines->first()->id)->count())->toBe(1);
});

it('creates a new cart line when addon selections differ', function () {
    $this->service->addToCart($this->user, [
        'store_id'           => $this->store->id,
        'product_variant_id' => $this->variant->id,
        'quantity'           => 1,
        'addons' => [
            ['addon_group_id' => $this->toppings->id, 'addon_item_id' => $this->cheese->id],
        ],
    ]);

    $this->service->addToCart($this->user, [
        'store_id'           => $this->store->id,
        'product_variant_id' => $this->variant->id,
        'quantity'           => 1,
        'addons' => [
            ['addon_group_id' => $this->toppings->id, 'addon_item_id' => $this->pepperoni->id],
        ],
    ]);

    $cart  = Cart::where('user_id', $this->user->id)->firstOrFail();
    $lines = CartItem::where('cart_id', $cart->id)->get();

    expect($lines)->toHaveCount(2)
        ->and($lines->pluck('addon_signature')->unique())->toHaveCount(2);
});

it('rejects more than one selection in a SINGLE-type group', function () {
    $size = AddonGroup::create([
        'seller_id'      => $this->seller->id,
        'title'          => 'Size',
        'selection_type' => AddonGroupSelectionTypeEnum::SINGLE->value,
        'is_required'    => false,
        'status'         => AddonGroupStatusEnum::ACTIVE->value,
    ]);
    $small = AddonItem::create([
        'addon_group_id' => $size->id,
        'title'          => 'Small',
        'price'          => 0,
        'is_available'   => true,
        'status'         => AddonGroupStatusEnum::ACTIVE->value,
    ]);
    $large = AddonItem::create([
        'addon_group_id' => $size->id,
        'title'          => 'Large',
        'price'          => 3,
        'is_available'   => true,
        'status'         => AddonGroupStatusEnum::ACTIVE->value,
    ]);
    foreach ([$small, $large] as $item) {
        StoreProductVariantAddon::create([
            'store_id'           => $this->store->id,
            'product_variant_id' => $this->variant->id,
            'addon_group_id'     => $size->id,
            'addon_item_id'      => $item->id,
        ]);
        StoreAddonItem::factory()->create([
            'store_id'      => $this->store->id,
            'addon_item_id' => $item->id,
            'price'         => $item->price,
            'is_available'  => true,
        ]);
    }

    $result = $this->service->addToCart($this->user, [
        'store_id'           => $this->store->id,
        'product_variant_id' => $this->variant->id,
        'quantity'           => 1,
        'addons' => [
            ['addon_group_id' => $size->id, 'addon_item_id' => $small->id],
            ['addon_group_id' => $size->id, 'addon_item_id' => $large->id],
        ],
    ]);

    expect($result['success'])->toBeFalse()
        ->and(CartItem::count())->toBe(0);
});

it('rejects when a required group has no selection', function () {
    $sauces = AddonGroup::create([
        'seller_id'      => $this->seller->id,
        'title'          => 'Sauces',
        'selection_type' => AddonGroupSelectionTypeEnum::SINGLE->value,
        'is_required'    => true,
        'status'         => AddonGroupStatusEnum::ACTIVE->value,
    ]);
    $ranch = AddonItem::create([
        'addon_group_id' => $sauces->id,
        'title'          => 'Ranch',
        'price'          => 0.50,
        'is_available'   => true,
        'status'         => AddonGroupStatusEnum::ACTIVE->value,
    ]);
    StoreProductVariantAddon::create([
        'store_id'           => $this->store->id,
        'product_variant_id' => $this->variant->id,
        'addon_group_id'     => $sauces->id,
        'addon_item_id'      => $ranch->id,
    ]);
    StoreAddonItem::factory()->create([
        'store_id'      => $this->store->id,
        'addon_item_id' => $ranch->id,
        'price'         => 0.50,
        'is_available'  => true,
    ]);

    // Submit NO addon selections at all — required sauces group has nothing → reject.
    $result = $this->service->addToCart($this->user, [
        'store_id'           => $this->store->id,
        'product_variant_id' => $this->variant->id,
        'quantity'           => 1,
    ]);

    expect($result['success'])->toBeFalse()
        ->and(CartItem::count())->toBe(0);
});

it('rejects addons that are not attached to the variant at this store', function () {
    // Build an item in the same group that was NOT attached to the variant.
    $orphan = AddonItem::create([
        'addon_group_id' => $this->toppings->id,
        'title'          => 'Mushrooms',
        'price'          => 1.00,
        'is_available'   => true,
        'status'         => AddonGroupStatusEnum::ACTIVE->value,
    ]);

    $result = $this->service->addToCart($this->user, [
        'store_id'           => $this->store->id,
        'product_variant_id' => $this->variant->id,
        'quantity'           => 1,
        'addons' => [
            ['addon_group_id' => $this->toppings->id, 'addon_item_id' => $orphan->id],
        ],
    ]);

    expect($result['success'])->toBeFalse()
        ->and(CartItem::count())->toBe(0);
});

it('rejects when addon store inventory stock is less than cart quantity', function () {
    // Cheese has 50 stock; ask for 60 — should fail before any cart row is written.
    StoreAddonItem::where('store_id', $this->store->id)
        ->where('addon_item_id', $this->cheese->id)
        ->update(['stock' => 5]);

    $result = $this->service->addToCart($this->user, [
        'store_id'           => $this->store->id,
        'product_variant_id' => $this->variant->id,
        'quantity'           => 6,
        'addons' => [
            ['addon_group_id' => $this->toppings->id, 'addon_item_id' => $this->cheese->id],
        ],
    ]);

    expect($result['success'])->toBeFalse()
        ->and($result['data']['addon_item_id'] ?? null)->toBe($this->cheese->id)
        ->and((int) ($result['data']['available_stock'] ?? -1))->toBe(5)
        ->and(CartItem::count())->toBe(0);
});

it('rejects when merging a cart line would exceed addon stock', function () {
    // Seed an existing line with 3 pizzas + 1 cheese addon.
    StoreAddonItem::where('store_id', $this->store->id)
        ->where('addon_item_id', $this->cheese->id)
        ->update(['stock' => 4]);

    $this->service->addToCart($this->user, [
        'store_id'           => $this->store->id,
        'product_variant_id' => $this->variant->id,
        'quantity'           => 3,
        'addons' => [
            ['addon_group_id' => $this->toppings->id, 'addon_item_id' => $this->cheese->id],
        ],
    ]);

    // Add the same pair again with qty 2 → merged qty = 5 > cheese stock of 4.
    $result = $this->service->addToCart($this->user, [
        'store_id'           => $this->store->id,
        'product_variant_id' => $this->variant->id,
        'quantity'           => 2,
        'addons' => [
            ['addon_group_id' => $this->toppings->id, 'addon_item_id' => $this->cheese->id],
        ],
    ]);

    expect($result['success'])->toBeFalse();

    // Existing cart line should be untouched — still at qty 3.
    $line = CartItem::first();
    expect((int) $line->quantity)->toBe(3);
});

it('rejects updateCartItemQuantity when the new quantity exceeds existing addon stock', function () {
    StoreAddonItem::where('store_id', $this->store->id)
        ->where('addon_item_id', $this->cheese->id)
        ->update(['stock' => 4]);

    $this->service->addToCart($this->user, [
        'store_id'           => $this->store->id,
        'product_variant_id' => $this->variant->id,
        'quantity'           => 2,
        'addons' => [
            ['addon_group_id' => $this->toppings->id, 'addon_item_id' => $this->cheese->id],
        ],
    ]);

    $line = CartItem::first();

    $result = $this->service->updateCartItemQuantity(
        user: $this->user,
        cartItemId: $line->id,
        quantity: 10, // > cheese stock of 4
    );

    expect($result['success'])->toBeFalse();

    $line->refresh();
    expect((int) $line->quantity)->toBe(2);
});

it('includes addon amounts in the cart items_total and per-line addons_total', function () {
    // 2 pizzas × ($10 product + $1.50 cheese + $2.00 pepperoni) = 2 × 13.50 = 27.00
    $this->service->addToCart($this->user, [
        'store_id'           => $this->store->id,
        'product_variant_id' => $this->variant->id,
        'quantity'           => 2,
        'addons' => [
            ['addon_group_id' => $this->toppings->id, 'addon_item_id' => $this->cheese->id],
            ['addon_group_id' => $this->toppings->id, 'addon_item_id' => $this->pepperoni->id],
        ],
    ]);

    $cart = $this->service->getCart($this->user);

    expect($cart['success'])->toBeTrue();

    // Pull the enriched Cart model out of the response to read the items
    // annotated by calculateCartTotals().
    /** @var Cart $cartModel */
    $cartModel = $cart['data']['cart'];

    expect((float) $cartModel->items_total)->toBe(27.0);

    $line = $cartModel->items->first();
    expect((float) $line->addons_total)->toBe(7.0); // 2 × (1.50 + 2.00)
    // Product-only line total is 2 × $10 = $20; `price` should fold in the $7 addon line total.
    expect((float) $line->price)->toBe(27.0);

    // Payment summary reflects the same total (no location → default summary path).
    expect((float) $cartModel->payment_summary['items_total'])->toBe(27.0);

    // Resource should expose the combined line totals for clients.
    $rendered = (new App\Http\Resources\User\CartItemResource($line))
        ->toArray(request());

    // variant.price = 10, special_price = 10 (factory set both equal). With 2
    // pizzas + $7 addons, both combined totals should read 27.00.
    expect($rendered['addons_total'])->toBe(7.0)
        ->and($rendered['total_item_price'])->toBe(27.0)
        ->and($rendered['total_item_special_price'])->toBe(27.0);
});

it('returns 0 for total_item_special_price when no special price is configured', function () {
    // Drop the special price on the variant so only the regular price applies.
    $this->variant->storeProductVariants()
        ->where('store_id', $this->store->id)
        ->update(['special_price' => 0]);

    $this->service->addToCart($this->user, [
        'store_id'           => $this->store->id,
        'product_variant_id' => $this->variant->id,
        'quantity'           => 1,
        'addons' => [
            ['addon_group_id' => $this->toppings->id, 'addon_item_id' => $this->cheese->id],
        ],
    ]);

    $cart  = $this->service->getCart($this->user);
    /** @var Cart $cartModel */
    $cartModel = $cart['data']['cart'];
    $line      = $cartModel->items->first();

    $rendered = (new App\Http\Resources\User\CartItemResource($line))
        ->toArray(request());

    // 1 × $10 product + $1.50 cheese = $11.50 on the regular total,
    // and 0 on the special total because no special price is set.
    expect($rendered['total_item_price'])->toBe(11.5)
        ->and($rendered['total_item_special_price'])->toBe(0.0);
});

it('rejects an addon whose store inventory is marked unavailable', function () {
    // Flip the store-level inventory row for cheese to unavailable.
    StoreAddonItem::where('store_id', $this->store->id)
        ->where('addon_item_id', $this->cheese->id)
        ->update(['is_available' => false]);

    $result = $this->service->addToCart($this->user, [
        'store_id'           => $this->store->id,
        'product_variant_id' => $this->variant->id,
        'quantity'           => 1,
        'addons' => [
            ['addon_group_id' => $this->toppings->id, 'addon_item_id' => $this->cheese->id],
        ],
    ]);

    expect($result['success'])->toBeFalse()
        ->and(CartItem::count())->toBe(0);
});

it('replaces the addon set when update is called with a new addons payload', function () {
    // Start with cheese only.
    $this->service->addToCart($this->user, [
        'store_id'           => $this->store->id,
        'product_variant_id' => $this->variant->id,
        'quantity'           => 1,
        'addons' => [
            ['addon_group_id' => $this->toppings->id, 'addon_item_id' => $this->cheese->id],
        ],
    ]);

    $line = CartItem::first();
    $originalSignature = $line->addon_signature;

    $result = $this->service->updateCartItemQuantity(
        user: $this->user,
        cartItemId: $line->id,
        quantity: 3,
        addons: [
            ['addon_group_id' => $this->toppings->id, 'addon_item_id' => $this->pepperoni->id],
        ],
    );

    expect($result['success'])->toBeTrue();

    $line->refresh();
    expect((int) $line->quantity)->toBe(3)
        ->and($line->addon_signature)->not->toBeNull()
        ->and($line->addon_signature)->not->toBe($originalSignature);

    $addonRows = CartItemAddon::where('cart_item_id', $line->id)->get();
    expect($addonRows)->toHaveCount(1)
        ->and((int) $addonRows->first()->addon_item_id)->toBe($this->pepperoni->id);
});

it('leaves addons untouched when update is called without an addons payload', function () {
    $this->service->addToCart($this->user, [
        'store_id'           => $this->store->id,
        'product_variant_id' => $this->variant->id,
        'quantity'           => 1,
        'addons' => [
            ['addon_group_id' => $this->toppings->id, 'addon_item_id' => $this->cheese->id],
        ],
    ]);

    $line = CartItem::first();
    $signatureBefore = $line->addon_signature;
    $addonIdsBefore  = CartItemAddon::where('cart_item_id', $line->id)
        ->pluck('addon_item_id')->sort()->values()->all();

    // No `addons` key — tri-state says "keep existing selections".
    $result = $this->service->updateCartItemQuantity(
        user: $this->user,
        cartItemId: $line->id,
        quantity: 5,
    );

    expect($result['success'])->toBeTrue();

    $line->refresh();
    $addonIdsAfter = CartItemAddon::where('cart_item_id', $line->id)
        ->pluck('addon_item_id')->sort()->values()->all();

    expect((int) $line->quantity)->toBe(5)
        ->and($line->addon_signature)->toBe($signatureBefore)
        ->and($addonIdsAfter)->toEqual($addonIdsBefore);
});

it('clears the addon set when update is called with an empty addons array', function () {
    $this->service->addToCart($this->user, [
        'store_id'           => $this->store->id,
        'product_variant_id' => $this->variant->id,
        'quantity'           => 1,
        'addons' => [
            ['addon_group_id' => $this->toppings->id, 'addon_item_id' => $this->cheese->id],
        ],
    ]);

    $line = CartItem::first();

    $result = $this->service->updateCartItemQuantity(
        user: $this->user,
        cartItemId: $line->id,
        quantity: 2,
        addons: [],
    );

    expect($result['success'])->toBeTrue();

    $line->refresh();
    expect((int) $line->quantity)->toBe(2)
        ->and($line->addon_signature)->toBeNull()
        ->and(CartItemAddon::where('cart_item_id', $line->id)->count())->toBe(0);
});

/*
 |----------------------------------------------------------------------
 | syncMultiStoreCart — addon payload coverage
 |----------------------------------------------------------------------
 | These tests exercise the bulk sync entry point used by the mobile app
 | when it pushes a local cart snapshot. `syncMultiStoreCart` now forwards
 | the per-item addon selections into `addToCart`, so the existing
 | resolution/validation logic takes care of the semantic checks.
 */

it('syncMultiStoreCart snapshots addons onto the synced cart line', function () {
    $result = $this->service->syncMultiStoreCart($this->user, [
        'items' => [
            [
                'store_id'           => $this->store->id,
                'product_variant_id' => $this->variant->id,
                'quantity'           => 2,
                'addons' => [
                    ['addon_group_id' => $this->toppings->id, 'addon_item_id' => $this->cheese->id],
                    ['addon_group_id' => $this->toppings->id, 'addon_item_id' => $this->pepperoni->id],
                ],
            ],
        ],
    ]);

    expect($result['success'])->toBeTrue()
        ->and($result['data']['synced_items'])->toHaveCount(1)
        ->and($result['data']['failed_items'])->toBeEmpty();

    // Echoed addon payload on the synced entry lets clients reconcile.
    expect($result['data']['synced_items'][0]['addons'])->toHaveCount(2);

    // Line should exist with both addon rows persisted and a signature set.
    $line = CartItem::first();
    expect((int) $line->quantity)->toBe(2)
        ->and($line->addon_signature)->not->toBeNull()
        ->and(CartItemAddon::where('cart_item_id', $line->id)->count())->toBe(2);
});

it('syncMultiStoreCart routes failed addon items into failed_items with details', function () {
    // Flip the store inventory for cheese to unavailable — the sync should
    // reject that line and surface the failure without rolling back
    // neighbouring successful lines.
    StoreAddonItem::where('store_id', $this->store->id)
        ->where('addon_item_id', $this->cheese->id)
        ->update(['is_available' => false]);

    $result = $this->service->syncMultiStoreCart($this->user, [
        'items' => [
            [
                'store_id'           => $this->store->id,
                'product_variant_id' => $this->variant->id,
                'quantity'           => 1,
                'addons' => [
                    ['addon_group_id' => $this->toppings->id, 'addon_item_id' => $this->cheese->id],
                ],
            ],
        ],
    ]);

    expect($result['success'])->toBeTrue() // overall operation still succeeded
        ->and($result['data']['failed_items'])->toHaveCount(1)
        ->and($result['data']['synced_items'])->toBeEmpty();

    $failure = $result['data']['failed_items'][0];
    expect($failure['reason'])->not->toBeEmpty()
        ->and((int) ($failure['details']['addon_item_id'] ?? 0))->toBe((int) $this->cheese->id);

    // No cart line should have been created.
    expect(CartItem::count())->toBe(0);
});

it('syncMultiStoreCart treats items without addons key as addon-free', function () {
    // Sanity: sync must still work for plain items so existing mobile
    // clients that don't send `addons` at all keep working.
    $result = $this->service->syncMultiStoreCart($this->user, [
        'items' => [
            [
                'store_id'           => $this->store->id,
                'product_variant_id' => $this->variant->id,
                'quantity'           => 1,
            ],
        ],
    ]);

    expect($result['success'])->toBeTrue()
        ->and($result['data']['synced_items'])->toHaveCount(1)
        ->and($result['data']['synced_items'][0]['addons'])->toBe([]);

    $line = CartItem::first();
    expect($line->addon_signature)->toBeNull()
        ->and(CartItemAddon::where('cart_item_id', $line->id)->count())->toBe(0);
});
