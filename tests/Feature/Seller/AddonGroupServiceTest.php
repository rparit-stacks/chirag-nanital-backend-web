<?php

use App\Enums\Addon\AddonGroupSelectionTypeEnum;
use App\Enums\Addon\AddonGroupStatusEnum;
use App\Enums\Addon\AddonItemIndicatorEnum;
use App\Models\AddonGroup;
use App\Models\AddonItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Seller;
use App\Models\Store;
use App\Models\StoreAddonItem;
use App\Models\StoreProductVariantAddon;
use App\Services\AddonGroupService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = app(AddonGroupService::class);
    $this->seller  = Seller::factory()->create();
});

it('creates an addon group together with its items in a single call', function () {
    $group = $this->service->createWithItems($this->seller, [
        'title'          => 'Toppings',
        'selection_type' => AddonGroupSelectionTypeEnum::MULTIPLE->value,
        'is_required'    => true,
        'sort_order'     => 1,
        'status'         => AddonGroupStatusEnum::ACTIVE->value,
        'items' => [
            [
                'title'        => 'Extra cheese',
                'price'        => 1.50,
                'cost'         => 0.40,
                'indicator'    => AddonItemIndicatorEnum::VEG->value,
                'is_available' => true,
                'status'       => AddonGroupStatusEnum::ACTIVE->value,
            ],
            [
                'title'        => 'Pepperoni',
                'price'        => 2.00,
                'indicator'    => AddonItemIndicatorEnum::NON_VEG->value,
                'is_available' => true,
                'status'       => AddonGroupStatusEnum::ACTIVE->value,
            ],
        ],
    ]);

    expect($group)->toBeInstanceOf(AddonGroup::class)
        ->and($group->seller_id)->toBe($this->seller->id)
        ->and($group->is_required)->toBeTrue()
        ->and($group->items)->toHaveCount(2)
        ->and($group->items->first()->title)->toBe('Extra cheese')
        ->and((float) $group->items->first()->price)->toBe(1.50);
});

it('reconciles items on update – adds, updates, and removes', function () {
    $group = $this->service->createWithItems($this->seller, [
        'title'          => 'Sides',
        'selection_type' => AddonGroupSelectionTypeEnum::SINGLE->value,
        'is_required'    => false,
        'status'         => AddonGroupStatusEnum::ACTIVE->value,
        'items' => [
            ['title' => 'Fries',   'price' => 2, 'status' => AddonGroupStatusEnum::ACTIVE->value],
            ['title' => 'Wedges',  'price' => 3, 'status' => AddonGroupStatusEnum::ACTIVE->value],
        ],
    ]);

    $existing = $group->items->first();

    $updated = $this->service->updateWithItems($group->fresh('items'), [
        'title'          => 'Sides Updated',
        'selection_type' => AddonGroupSelectionTypeEnum::SINGLE->value,
        'is_required'    => false,
        'status'         => AddonGroupStatusEnum::ACTIVE->value,
        'items' => [
            // Update existing
            ['id' => $existing->id, 'title' => 'Curly Fries', 'price' => 2.5, 'status' => AddonGroupStatusEnum::ACTIVE->value],
            // New
            ['title' => 'Onion Rings', 'price' => 3.5, 'status' => AddonGroupStatusEnum::ACTIVE->value],
            // Note: "Wedges" is dropped → must be removed
        ],
    ]);

    expect($updated->title)->toBe('Sides Updated')
        ->and($updated->items)->toHaveCount(2)
        ->and($updated->items->pluck('title')->all())
        ->toEqualCanonicalizing(['Curly Fries', 'Onion Rings']);
});

it('soft deletes the group together with its items', function () {
    $group = $this->service->createWithItems($this->seller, [
        'title'          => 'Sauces',
        'selection_type' => AddonGroupSelectionTypeEnum::MULTIPLE->value,
        'status'         => AddonGroupStatusEnum::ACTIVE->value,
        'items' => [
            ['title' => 'Garlic',    'price' => 0.5, 'status' => AddonGroupStatusEnum::ACTIVE->value],
            ['title' => 'Ranch',     'price' => 0.5, 'status' => AddonGroupStatusEnum::ACTIVE->value],
        ],
    ]);

    $this->service->deleteGroup($group);

    expect(AddonGroup::find($group->id))->toBeNull()
        ->and(AddonGroup::withTrashed()->find($group->id))->not->toBeNull()
        ->and($group->items()->count())->toBe(0);
});

it('cascades deleteGroup to store_product_variant_addons and store_addon_items', function () {
    // Seed a full graph: group + items + store + product/variant + attachments + inventory.
    $group = $this->service->createWithItems($this->seller, [
        'title'          => 'Toppings',
        'selection_type' => AddonGroupSelectionTypeEnum::MULTIPLE->value,
        'status'         => AddonGroupStatusEnum::ACTIVE->value,
        'items' => [
            ['title' => 'Extra cheese', 'price' => 1.5, 'status' => AddonGroupStatusEnum::ACTIVE->value],
            ['title' => 'Pepperoni',    'price' => 2.0, 'status' => AddonGroupStatusEnum::ACTIVE->value],
        ],
    ]);

    $cheese = $group->items->firstWhere('title', 'Extra cheese');
    $pepp   = $group->items->firstWhere('title', 'Pepperoni');

    $store = Store::forceCreate([
        'seller_id' => $this->seller->id,
        'name'      => 'Downtown',
    ]);

    $product = Product::forceCreate([
        'seller_id' => $this->seller->id,
        'title'     => 'Pizza',
    ]);

    $variant = ProductVariant::forceCreate([
        'product_id' => $product->id,
        'title'      => '14 inch',
    ]);

    StoreProductVariantAddon::create([
        'store_id'           => $store->id,
        'product_variant_id' => $variant->id,
        'addon_group_id'     => $group->id,
        'addon_item_id'      => $cheese->id,
    ]);
    StoreProductVariantAddon::create([
        'store_id'           => $store->id,
        'product_variant_id' => $variant->id,
        'addon_group_id'     => $group->id,
        'addon_item_id'      => $pepp->id,
    ]);

    StoreAddonItem::create([
        'store_id'      => $store->id,
        'addon_item_id' => $cheese->id,
        'price'         => 30,
        'stock'         => 10,
        'is_available'  => true,
    ]);
    StoreAddonItem::create([
        'store_id'      => $store->id,
        'addon_item_id' => $pepp->id,
        'price'         => 25,
        'stock'         => 5,
        'is_available'  => true,
    ]);

    expect(StoreProductVariantAddon::count())->toBe(2)
        ->and(StoreAddonItem::count())->toBe(2);

    $this->service->deleteGroup($group);

    expect(StoreProductVariantAddon::count())->toBe(0)
        ->and(StoreAddonItem::count())->toBe(0)
        ->and(StoreProductVariantAddon::withTrashed()->count())->toBe(2)
        ->and(StoreAddonItem::withTrashed()->count())->toBe(2);
});

it('cascades removed items on updateWithItems to attachments and inventory', function () {
    $group = $this->service->createWithItems($this->seller, [
        'title'          => 'Sides',
        'selection_type' => AddonGroupSelectionTypeEnum::SINGLE->value,
        'status'         => AddonGroupStatusEnum::ACTIVE->value,
        'items' => [
            ['title' => 'Fries',  'price' => 2, 'status' => AddonGroupStatusEnum::ACTIVE->value],
            ['title' => 'Wedges', 'price' => 3, 'status' => AddonGroupStatusEnum::ACTIVE->value],
        ],
    ]);

    $fries  = $group->items->firstWhere('title', 'Fries');
    $wedges = $group->items->firstWhere('title', 'Wedges');

    $store = Store::forceCreate([
        'seller_id' => $this->seller->id,
        'name'      => 'Downtown',
    ]);

    $product = Product::forceCreate([
        'seller_id' => $this->seller->id,
        'title'     => 'Burger',
    ]);

    $variant = ProductVariant::forceCreate([
        'product_id' => $product->id,
        'title'      => 'Regular',
    ]);

    foreach ([$fries, $wedges] as $item) {
        StoreProductVariantAddon::create([
            'store_id'           => $store->id,
            'product_variant_id' => $variant->id,
            'addon_group_id'     => $group->id,
            'addon_item_id'      => $item->id,
        ]);
        StoreAddonItem::create([
            'store_id'      => $store->id,
            'addon_item_id' => $item->id,
            'price'         => 5,
            'stock'         => 10,
            'is_available'  => true,
        ]);
    }

    // Drop "Wedges" from the group via the edit flow.
    $this->service->updateWithItems($group->fresh('items'), [
        'title'          => $group->title,
        'selection_type' => AddonGroupSelectionTypeEnum::SINGLE->value,
        'status'         => AddonGroupStatusEnum::ACTIVE->value,
        'items' => [
            ['id' => $fries->id, 'title' => 'Fries', 'price' => 2, 'status' => AddonGroupStatusEnum::ACTIVE->value],
        ],
    ]);

    // Fries rows survive.
    expect(StoreProductVariantAddon::where('addon_item_id', $fries->id)->count())->toBe(1)
        ->and(StoreAddonItem::where('addon_item_id', $fries->id)->count())->toBe(1);

    // Wedges rows are gone (soft-deleted).
    expect(StoreProductVariantAddon::where('addon_item_id', $wedges->id)->count())->toBe(0)
        ->and(StoreAddonItem::where('addon_item_id', $wedges->id)->count())->toBe(0)
        ->and(StoreProductVariantAddon::withTrashed()->where('addon_item_id', $wedges->id)->count())->toBe(1)
        ->and(StoreAddonItem::withTrashed()->where('addon_item_id', $wedges->id)->count())->toBe(1);
});
