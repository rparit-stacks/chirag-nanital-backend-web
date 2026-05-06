<?php

use App\Enums\Addon\AddonGroupSelectionTypeEnum;
use App\Enums\Addon\AddonGroupStatusEnum;
use App\Enums\GuardNameEnum;
use App\Enums\Order\OrderItemReturnPickupStatusEnum;
use App\Enums\Order\OrderItemReturnStatusEnum;
use App\Http\Resources\DeliveryBoy\DeliveryBoyReturnPickupResource;
use App\Models\AddonGroup;
use App\Models\AddonItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemAddon;
use App\Models\OrderItemReturn;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Seller;
use App\Models\Store;
use App\Models\StoreAddonItem;
use App\Models\StoreProductVariant;
use App\Models\StoreProductVariantAddon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

/**
 * Focused feature test for addon exposure on returned order items.
 *
 * Verifies that once a return has been created from an order item carrying
 * addons, the delivery-boy pickup resource surfaces the same addon snapshot
 * that was on the original order item. The courier needs this to know what
 * physically to collect on return.
 */
beforeEach(function () {
    Event::fake();

    $this->user = User::forceCreate([
        'name'         => 'Return Addon Tester',
        'email'        => 'return.addon.'.uniqid().'@example.test',
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

    StoreAddonItem::factory()->create([
        'store_id'      => $this->store->id,
        'addon_item_id' => $this->cheese->id,
        'price'         => 1.50,
        'cost'          => 0.50,
        'stock'         => 50,
        'is_available'  => true,
    ]);

    // Build an order + item + addon directly (bypasses OrderService so the
    // test stays narrow). Same "minimal orders row" pattern used in the
    // existing order/cart addon tests.
    $this->order = Order::forceCreate([
        'uuid'                  => (string) \Illuminate\Support\Str::uuid(),
        'user_id'               => $this->user->id,
        'slug'                  => 'order-return-addon-'.uniqid(),
        'email'                 => $this->user->email,
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
        'billing_name'          => $this->user->name,
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
        'shipping_name'         => $this->user->name,
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

    $this->orderItem = OrderItem::create([
        'order_id'           => $this->order->id,
        'product_id'         => $this->product->id,
        'product_variant_id' => $this->variant->id,
        'store_id'           => $this->store->id,
        'title'              => $this->product->title,
        'variant_title'      => $this->variant->title,
        'quantity'           => 2,
        'price'              => 10,
        'subtotal'           => 23, // 2 × $10 + 2 × $1.50 cheese
        'status'             => 'delivered',
    ]);

    OrderItemAddon::create([
        'order_item_id'  => $this->orderItem->id,
        'addon_group_id' => $this->toppings->id,
        'addon_item_id'  => $this->cheese->id,
        'price'          => 1.50,
    ]);

    $this->return = OrderItemReturn::forceCreate([
        'order_item_id'   => $this->orderItem->id,
        'order_id'        => $this->order->id,
        'user_id'         => $this->user->id,
        'seller_id'       => $this->seller->id,
        'store_id'        => $this->store->id,
        'reason'          => 'Wrong topping',
        'refund_amount'   => 11.50,
        'pickup_status'   => OrderItemReturnPickupStatusEnum::PENDING()->value,
        'return_status'   => OrderItemReturnStatusEnum::SELLER_APPROVED()->value,
    ]);
});

it('DeliveryBoyReturnPickupResource exposes addon snapshot under order_item', function () {
    // Simulate the eager loads the controller performs on the return.
    $this->return->load([
        'order',
        'orderItem.product',
        'orderItem.variant',
        'orderItem.addons.addonGroup',
        'orderItem.addons.addonItem',
        'store',
        'user',
    ]);

    $rendered = (new DeliveryBoyReturnPickupResource($this->return))
        ->toArray(request());

    expect($rendered)->toHaveKey('order_item')
        ->and($rendered['order_item'])->toHaveKey('addons')
        ->and($rendered['order_item']['addons'])->toHaveCount(1);

    $addon = $rendered['order_item']['addons'][0];

    expect((int) $addon['addon_group_id'])->toBe((int) $this->toppings->id)
        ->and((int) $addon['addon_item_id'])->toBe((int) $this->cheese->id)
        ->and((float) $addon['price'])->toBe(1.50)
        ->and($addon['group']['title'])->toBe('Toppings')
        ->and($addon['item']['title'])->toBe('Extra cheese');

    // 2 × $1.50 = $3.00
    expect((float) $rendered['order_item']['addons_total'])->toBe(3.0)
        ->and((int) $rendered['order_item']['quantity'])->toBe(2);
});

it('DeliveryBoyReturnPickupResource lazy-loads addons when not eager-loaded', function () {
    // Deliberately skip the addons eager-load to confirm the resource's
    // lazy-load fallback works. Without this, panel views that hit the
    // resource without the eager-load would 500.
    $this->return->load(['order', 'orderItem', 'store', 'user']);

    $rendered = (new DeliveryBoyReturnPickupResource($this->return))
        ->toArray(request());

    expect($rendered['order_item']['addons'])->toHaveCount(1)
        ->and((int) $rendered['order_item']['addons'][0]['addon_item_id'])->toBe((int) $this->cheese->id);
});

it('DeliveryBoyReturnPickupResource returns empty addons when the order item has none', function () {
    $barewOrderItem = OrderItem::create([
        'order_id'           => $this->order->id,
        'product_id'         => $this->product->id,
        'product_variant_id' => $this->variant->id,
        'store_id'           => $this->store->id,
        'title'              => $this->product->title,
        'variant_title'      => $this->variant->title,
        'quantity'           => 1,
        'price'              => 10,
        'subtotal'           => 10,
        'status'             => 'delivered',
    ]);

    $bareReturn = OrderItemReturn::forceCreate([
        'order_item_id'   => $barewOrderItem->id,
        'order_id'        => $this->order->id,
        'user_id'         => $this->user->id,
        'seller_id'       => $this->seller->id,
        'store_id'        => $this->store->id,
        'reason'          => 'Not needed',
        'refund_amount'   => 10.00,
        'pickup_status'   => OrderItemReturnPickupStatusEnum::PENDING()->value,
        'return_status'   => OrderItemReturnStatusEnum::SELLER_APPROVED()->value,
    ]);

    $bareReturn->load(['orderItem.addons.addonGroup', 'orderItem.addons.addonItem']);

    $rendered = (new DeliveryBoyReturnPickupResource($bareReturn))
        ->toArray(request());

    expect($rendered['order_item']['addons'])->toBe([])
        ->and((float) $rendered['order_item']['addons_total'])->toBe(0.0);
});
