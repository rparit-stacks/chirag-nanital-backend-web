<?php

use App\Enums\Addon\AddonGroupSelectionTypeEnum;
use App\Enums\Addon\AddonGroupStatusEnum;
use App\Enums\GuardNameEnum;
use App\Enums\Order\OrderItemStatusEnum;
use App\Events\Order\OrderPlaced;
use App\Events\Order\OrderStatusUpdated;
use App\Listeners\Order\UpdateStockInventory;
use App\Listeners\Order\UpdateStockOnOrderStatusChange;
use App\Models\AddonGroup;
use App\Models\AddonItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemAddon;
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
 * Minimal `orders` row factory — the orders table carries a wide set of
 * billing/shipping fields that aren't interesting to the tests below, so we
 * fill them with placeholder values here and keep the tests focused on the
 * addon behaviour.
 */
function makeTestOrder(User $user): O
{
    return Order::forceCreate([
        'uuid'                  => (string) \Illuminate\Support\Str::uuid(),
        'user_id'               => $user->id,
        'slug'                  => 'order-addon-'.uniqid(),
        'email'                 => $user->email,
        'ip_address'            => '127.0.0.1',
        'currency_code'         => 'USD',
        'currency_rate'         => 1,
        'payment_method'        => 'cod',
        'payment_status'        => 'pending',
        'fulfillment_type'      => 'hyperlocal',
        'wallet_balance'        => 0,
        'subtotal'              => 23.0,
        'total_payable'         => 23.0,
        'final_total'           => 23.0,
        'status'                => 'pending',
        'billing_name'          => $user->name,
        'billing_address_1'     => 'Test street',
        'billing_landmark'      => 'Test landmark',
        'billing_zip'           => '00000',
        'billing_phone'         => '+10000000000',
        'billing_address_type'  => 'home',
        'billing_latitude'      => 0,
        'billing_longitude'     => 0,
        'billing_city'          => 'City',
        'billing_state'         => 'State',
        'billing_country'       => 'Country',
        'billing_country_code'  => 'US',
        'shipping_name'         => $user->name,
        'shipping_address_1'    => 'Test street',
        'shipping_landmark'     => 'Test landmark',
        'shipping_zip'          => '00000',
        'shipping_phone'        => '+10000000000',
        'shipping_address_type' => 'home',
        'shipping_latitude'     => 0,
        'shipping_longitude'    => 0,
        'shipping_city'         => 'City',
        'shipping_state'        => 'State',
        'shipping_country'      => 'Country',
        'shipping_country_code' => 'US',
    ]);
}

/**
 * Feature tests for the place-order addon pathway.
 *
 * Focus areas:
 *  - `CartService::validateCartAddonsForCheckout` re-runs the cart-time
 *    validations against snapshotted `cart_item_addons` rows.
 *  - `UpdateStockInventory` listener decrements `store_addon_items.stock`
 *    for every addon attached to the placed order.
 *  - `OrderItemAddon` reads/writes its own table cleanly (guards against
 *    the column-name mismatch that used to exist in the model).
 */
beforeEach(function () {
    Event::fake();

    $this->service = app(CartService::class);

    $this->user = User::forceCreate([
        'name'         => 'Order Addon Tester',
        'email'        => 'order.addon.'.uniqid().'@example.test',
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

    StoreProductVariantAddon::create([
        'store_id'           => $this->store->id,
        'product_variant_id' => $this->variant->id,
        'addon_group_id'     => $this->toppings->id,
        'addon_item_id'      => $this->cheese->id,
    ]);

    $this->cheeseInventory = StoreAddonItem::factory()->create([
        'store_id'      => $this->store->id,
        'addon_item_id' => $this->cheese->id,
        'price'         => 1.50,
        'cost'          => 0.50,
        'stock'         => 50,
        'is_available'  => true,
    ]);

    // Seed a typical "2 pizzas + cheese" cart line — reused by most tests.
    $this->service->addToCart($this->user, [
        'store_id'           => $this->store->id,
        'product_variant_id' => $this->variant->id,
        'quantity'           => 2,
        'addons' => [
            ['addon_group_id' => $this->toppings->id, 'addon_item_id' => $this->cheese->id],
        ],
    ]);
});

it('validateCartAddonsForCheckout returns null when the cart is clean', function () {
    $cart = CartService::getUserCart($this->user);

    $result = $this->service->validateCartAddonsForCheckout($cart);

    expect($result)->toBeNull();
});

it('validateCartAddonsForCheckout rejects when the addon was marked unavailable after add-to-cart', function () {
    // Seller toggles cheese unavailable at the store *after* the cart was built.
    StoreAddonItem::where('store_id', $this->store->id)
        ->where('addon_item_id', $this->cheese->id)
        ->update(['is_available' => false]);

    $cart = CartService::getUserCart($this->user);
    $result = $this->service->validateCartAddonsForCheckout($cart);

    expect($result)->not->toBeNull()
        ->and($result['success'])->toBeFalse();
});

it('validateCartAddonsForCheckout rejects when store addon stock drops below cart quantity', function () {
    // Simulate a concurrent order consuming stock — cheese stock drops to 1
    // while the cart still wants 2.
    StoreAddonItem::where('store_id', $this->store->id)
        ->where('addon_item_id', $this->cheese->id)
        ->update(['stock' => 1]);

    $cart = CartService::getUserCart($this->user);
    $result = $this->service->validateCartAddonsForCheckout($cart);

    expect($result)->not->toBeNull()
        ->and($result['success'])->toBeFalse()
        ->and((int) ($result['data']['available_stock'] ?? -1))->toBe(1);
});

it('validateCartAddonsForCheckout rejects when the addon was detached from the variant at the store', function () {
    // Seller removes the cheese attachment from this variant at the store.
    StoreProductVariantAddon::where('store_id', $this->store->id)
        ->where('product_variant_id', $this->variant->id)
        ->where('addon_item_id', $this->cheese->id)
        ->delete();

    $cart = CartService::getUserCart($this->user);
    $result = $this->service->validateCartAddonsForCheckout($cart);

    expect($result)->not->toBeNull()
        ->and($result['success'])->toBeFalse();
});

it('OrderItemAddon writes and reads its own table columns cleanly', function () {
    // Regression test for the old model mismatch (product_addon_*_id vs
    // addon_*_id) — ensure create() + refresh work end to end.
    $order = makeTestOrder($this->user);

    $orderItem = OrderItem::create([
        'order_id'           => $order->id,
        'product_id'         => $this->product->id,
        'product_variant_id' => $this->variant->id,
        'store_id'           => $this->store->id,
        'title'              => $this->product->title,
        'variant_title'      => $this->variant->title,
        'quantity'           => 2,
        'price'              => 10,
        'subtotal'           => 23, // 2 × $10 + 2 × $1.50 cheese
        'status'             => 'pending',
    ]);

    $addon = OrderItemAddon::create([
        'order_item_id'  => $orderItem->id,
        'addon_group_id' => $this->toppings->id,
        'addon_item_id'  => $this->cheese->id,
        'price'          => 1.50,
    ]);

    $addon->refresh();

    expect((int) $addon->addon_group_id)->toBe((int) $this->toppings->id)
        ->and((int) $addon->addon_item_id)->toBe((int) $this->cheese->id)
        ->and((float) $addon->price)->toBe(1.50)
        ->and($addon->addonGroup->id)->toBe($this->toppings->id)
        ->and($addon->addonItem->id)->toBe($this->cheese->id)
        ->and($orderItem->refresh()->addons()->count())->toBe(1);
});

it('UpdateStockInventory decrements store_addon_items.stock for each addon per order item quantity', function () {
    // Build a minimal order + item + addon so we can exercise the listener
    // directly without booting the full OrderService pipeline.
    $order = makeTestOrder($this->user);

    $orderItem = OrderItem::create([
        'order_id'           => $order->id,
        'product_id'         => $this->product->id,
        'product_variant_id' => $this->variant->id,
        'store_id'           => $this->store->id,
        'title'              => $this->product->title,
        'variant_title'      => $this->variant->title,
        'quantity'           => 2,
        'price'              => 10,
        'subtotal'           => 23,
        'status'             => 'pending',
    ]);

    OrderItemAddon::create([
        'order_item_id'  => $orderItem->id,
        'addon_group_id' => $this->toppings->id,
        'addon_item_id'  => $this->cheese->id,
        'price'          => 1.50,
    ]);

    $order->load(['items.addons']);

    // Call the listener directly — avoids firing unrelated listeners bound
    // to OrderPlaced (notifications, etc.).
    app(UpdateStockInventory::class)->handle(new OrderPlaced($order));

    $this->cheeseInventory->refresh();

    // Cheese started at 50; 2 pizzas × 1 cheese addon = 2 units decremented.
    expect((int) $this->cheeseInventory->stock)->toBe(48);
});

/**
 * Set up the common "order placed, addon stock already decremented" fixture
 * for the status-change restoration tests below. Returns the created
 * OrderItem so tests can dispatch the status transition.
 */
function makeOrderItemWithDecrementedAddonStock($ctx): OrderItem
{
    $order = makeTestOrder($ctx->user);

    $orderItem = OrderItem::create([
        'order_id'           => $order->id,
        'product_id'         => $ctx->product->id,
        'product_variant_id' => $ctx->variant->id,
        'store_id'           => $ctx->store->id,
        'title'              => $ctx->product->title,
        'variant_title'      => $ctx->variant->title,
        'quantity'           => 2,
        'price'              => 10,
        'subtotal'           => 23,
        'status'             => OrderItemStatusEnum::ACCEPTED()->value,
    ]);

    OrderItemAddon::create([
        'order_item_id'  => $orderItem->id,
        'addon_group_id' => $ctx->toppings->id,
        'addon_item_id'  => $ctx->cheese->id,
        'price'          => 1.50,
    ]);

    // Reflect the state post-OrderPlaced: addon stock has already been
    // decremented. Cheese starts at 50, decrement by 2 → 48.
    $ctx->cheeseInventory->update(['stock' => 48]);
    $ctx->cheeseInventory->refresh();

    $orderItem->load('addons');

    return $orderItem;
}

it('UpdateStockOnOrderStatusChange restores addon stock on CANCELLED', function () {
    $orderItem = makeOrderItemWithDecrementedAddonStock($this);

    app(UpdateStockOnOrderStatusChange::class)->handle(new OrderStatusUpdated(
        orderItem: $orderItem,
        oldStatus: OrderItemStatusEnum::ACCEPTED()->value,
        newStatus: OrderItemStatusEnum::CANCELLED()->value,
    ));

    $this->cheeseInventory->refresh();

    // Cheese back to the pre-order level: 48 + 2 = 50.
    expect((int) $this->cheeseInventory->stock)->toBe(50);
});

it('UpdateStockOnOrderStatusChange restores addon stock on REJECTED', function () {
    $orderItem = makeOrderItemWithDecrementedAddonStock($this);

    app(UpdateStockOnOrderStatusChange::class)->handle(new OrderStatusUpdated(
        orderItem: $orderItem,
        oldStatus: OrderItemStatusEnum::ACCEPTED()->value,
        newStatus: OrderItemStatusEnum::REJECTED()->value,
    ));

    $this->cheeseInventory->refresh();

    expect((int) $this->cheeseInventory->stock)->toBe(50);
});

it('UpdateStockOnOrderStatusChange restores addon stock on RETURNED', function () {
    $orderItem = makeOrderItemWithDecrementedAddonStock($this);

    app(UpdateStockOnOrderStatusChange::class)->handle(new OrderStatusUpdated(
        orderItem: $orderItem,
        oldStatus: OrderItemStatusEnum::DELIVERED()->value,
        newStatus: OrderItemStatusEnum::RETURNED()->value,
    ));

    $this->cheeseInventory->refresh();

    expect((int) $this->cheeseInventory->stock)->toBe(50);
});

it('UpdateStockOnOrderStatusChange restores addon stock on REFUNDED', function () {
    $orderItem = makeOrderItemWithDecrementedAddonStock($this);

    app(UpdateStockOnOrderStatusChange::class)->handle(new OrderStatusUpdated(
        orderItem: $orderItem,
        oldStatus: OrderItemStatusEnum::RETURNED()->value,
        newStatus: OrderItemStatusEnum::REFUNDED()->value,
    ));

    $this->cheeseInventory->refresh();

    expect((int) $this->cheeseInventory->stock)->toBe(50);
});

it('UpdateStockOnOrderStatusChange does not restore addon stock on non-terminal transitions', function () {
    $orderItem = makeOrderItemWithDecrementedAddonStock($this);

    // PENDING → ACCEPTED should not trigger any stock movement.
    app(UpdateStockOnOrderStatusChange::class)->handle(new OrderStatusUpdated(
        orderItem: $orderItem,
        oldStatus: OrderItemStatusEnum::PENDING()->value,
        newStatus: OrderItemStatusEnum::ACCEPTED()->value,
    ));

    $this->cheeseInventory->refresh();

    // Stock stays at the post-place-order level.
    expect((int) $this->cheeseInventory->stock)->toBe(48);
});
