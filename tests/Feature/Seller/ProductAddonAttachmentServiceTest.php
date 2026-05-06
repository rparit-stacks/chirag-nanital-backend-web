<?php

use App\Enums\Addon\AddonGroupSelectionTypeEnum;
use App\Enums\Addon\AddonGroupStatusEnum;
use App\Models\AddonGroup;
use App\Models\AddonItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Seller;
use App\Models\Store;
use App\Models\StoreAddonItem;
use App\Models\StoreProductVariant;
use App\Models\StoreProductVariantAddon;
use App\Services\ProductAddonAttachmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = app(ProductAddonAttachmentService::class);
    $this->seller  = Seller::factory()->create();

    $this->store1 = Store::forceCreate([
        'seller_id' => $this->seller->id,
        'name'      => 'Downtown',
    ]);
    $this->store2 = Store::forceCreate([
        'seller_id' => $this->seller->id,
        'name'      => 'Uptown',
    ]);

    $this->product = Product::forceCreate([
        'seller_id' => $this->seller->id,
        'title'     => 'Pizza',
    ]);

    $this->variant = ProductVariant::forceCreate([
        'product_id' => $this->product->id,
        'title'      => '14 inch',
    ]);

    // Variant is carried by store1 and store2.
    foreach ([$this->store1, $this->store2] as $store) {
        StoreProductVariant::forceCreate([
            'product_variant_id' => $this->variant->id,
            'store_id'           => $store->id,
            'sku'                => 'SKU-' . $store->id,
            'price'              => 10,
            'special_price'      => 10,
            'cost'               => 5,
            'stock'              => 100,
        ]);
    }

    $this->group = AddonGroup::create([
        'seller_id'      => $this->seller->id,
        'title'          => 'Toppings',
        'selection_type' => AddonGroupSelectionTypeEnum::MULTIPLE->value,
        'status'         => AddonGroupStatusEnum::ACTIVE->value,
    ]);

    $this->itemCheese = AddonItem::create([
        'addon_group_id' => $this->group->id,
        'title'          => 'Extra cheese',
        'price'          => 1.50,
        'status'         => AddonGroupStatusEnum::ACTIVE->value,
    ]);
    $this->itemPepp = AddonItem::create([
        'addon_group_id' => $this->group->id,
        'title'          => 'Pepperoni',
        'price'          => 2.00,
        'status'         => AddonGroupStatusEnum::ACTIVE->value,
    ]);
});

it('creates one mapping row per (store × item) when attaching a group to a variant', function () {
    $this->service->saveAttachment($this->seller, $this->variant, $this->group, [
        [
            'store_id' => $this->store1->id,
            'apply'    => true,
            'items' => [
                ['addon_item_id' => $this->itemCheese->id],
                ['addon_item_id' => $this->itemPepp->id],
            ],
        ],
        [
            'store_id' => $this->store2->id,
            'apply'    => true,
            'items' => [
                ['addon_item_id' => $this->itemCheese->id],
                ['addon_item_id' => $this->itemPepp->id],
            ],
        ],
    ]);

    expect(StoreProductVariantAddon::count())->toBe(4);

    $store1Cheese = StoreProductVariantAddon::where('store_id', $this->store1->id)
        ->where('addon_item_id', $this->itemCheese->id)->first();
    expect($store1Cheese)->not->toBeNull()
        ->and((int) $store1Cheese->product_variant_id)->toBe($this->variant->id)
        ->and((int) $store1Cheese->addon_group_id)->toBe($this->group->id);
});

it('removes rows for stores where apply is false on update', function () {
    // First: apply to both stores.
    $this->service->saveAttachment($this->seller, $this->variant, $this->group, [
        ['store_id' => $this->store1->id, 'apply' => true, 'items' => [
            ['addon_item_id' => $this->itemCheese->id],
        ]],
        ['store_id' => $this->store2->id, 'apply' => true, 'items' => [
            ['addon_item_id' => $this->itemCheese->id],
        ]],
    ]);
    expect(StoreProductVariantAddon::count())->toBe(2);

    // Now: remove store2.
    $this->service->saveAttachment($this->seller, $this->variant, $this->group, [
        ['store_id' => $this->store1->id, 'apply' => true, 'items' => [
            ['addon_item_id' => $this->itemCheese->id],
        ]],
        ['store_id' => $this->store2->id, 'apply' => false, 'items' => []],
    ]);

    expect(StoreProductVariantAddon::count())->toBe(1)
        ->and(StoreProductVariantAddon::where('store_id', $this->store2->id)->exists())->toBeFalse();
});

it('saves multiple (variant × group) attachments in a single bulk call', function () {
    $secondGroup = AddonGroup::create([
        'seller_id'      => $this->seller->id,
        'title'          => 'Sauces',
        'selection_type' => AddonGroupSelectionTypeEnum::SINGLE->value,
        'status'         => AddonGroupStatusEnum::ACTIVE->value,
    ]);
    $sauceItem = AddonItem::create([
        'addon_group_id' => $secondGroup->id,
        'title'          => 'Garlic',
        'price'          => 0.50,
        'status'         => AddonGroupStatusEnum::ACTIVE->value,
    ]);

    $result = $this->service->saveBulkAttachments($this->seller, [
        [
            'product_variant_id' => $this->variant->id,
            'addon_group_id'     => $this->group->id,
            'stores' => [
                ['store_id' => $this->store1->id, 'apply' => true, 'items' => [
                    ['addon_item_id' => $this->itemCheese->id],
                ]],
            ],
        ],
        [
            'product_variant_id' => $this->variant->id,
            'addon_group_id'     => $secondGroup->id,
            'stores' => [
                ['store_id' => $this->store2->id, 'apply' => true, 'items' => [
                    ['addon_item_id' => $sauceItem->id],
                ]],
            ],
        ],
    ]);

    expect($result['saved'])->toBe(2)
        ->and($result['skipped'])->toBe(0)
        ->and(StoreProductVariantAddon::count())->toBe(2);
});

it('skips bulk entries whose variant or group is not owned by the seller', function () {
    $otherSeller = Seller::factory()->create();
    $foreignGroup = AddonGroup::create([
        'seller_id'      => $otherSeller->id,
        'title'          => 'Foreign',
        'selection_type' => AddonGroupSelectionTypeEnum::MULTIPLE->value,
        'status'         => AddonGroupStatusEnum::ACTIVE->value,
    ]);

    $result = $this->service->saveBulkAttachments($this->seller, [
        [
            'product_variant_id' => $this->variant->id,
            'addon_group_id'     => $foreignGroup->id, // not owned — must skip
            'stores' => [
                ['store_id' => $this->store1->id, 'apply' => true, 'items' => [
                    ['addon_item_id' => $this->itemCheese->id],
                ]],
            ],
        ],
        [
            'product_variant_id' => $this->variant->id,
            'addon_group_id'     => $this->group->id,
            'stores' => [
                ['store_id' => $this->store1->id, 'apply' => true, 'items' => [
                    ['addon_item_id' => $this->itemCheese->id],
                ]],
            ],
        ],
    ]);

    expect($result['saved'])->toBe(1)
        ->and($result['skipped'])->toBe(1)
        ->and(StoreProductVariantAddon::count())->toBe(1);
});

it('detaches the entire attachment across stores', function () {
    $this->service->saveAttachment($this->seller, $this->variant, $this->group, [
        ['store_id' => $this->store1->id, 'apply' => true, 'items' => [
            ['addon_item_id' => $this->itemCheese->id],
        ]],
        ['store_id' => $this->store2->id, 'apply' => true, 'items' => [
            ['addon_item_id' => $this->itemPepp->id],
        ]],
    ]);

    $this->service->detachAttachment($this->seller, $this->variant, $this->group);

    expect(StoreProductVariantAddon::count())->toBe(0);
});

it('exposes store-level inventory in the form payload', function () {
    // Seed one store_addon_items row for cheese at store1.
    StoreAddonItem::create([
        'store_id'      => $this->store1->id,
        'addon_item_id' => $this->itemCheese->id,
        'price'         => 30,
        'cost'          => 12,
        'stock'         => 100,
        'is_available'  => true,
    ]);

    $payload = $this->service->buildFormPayload($this->seller, $this->variant, $this->group);

    expect($payload['inventory']->count())->toBe(1);

    $row = $payload['inventory']->first();
    expect((int) $row['store_id'])->toBe($this->store1->id)
        ->and((int) $row['addon_item_id'])->toBe($this->itemCheese->id)
        ->and((float) $row['price'])->toBe(30.0)
        ->and((int) $row['stock'])->toBe(100)
        ->and($row['is_available'])->toBeTrue();
});
