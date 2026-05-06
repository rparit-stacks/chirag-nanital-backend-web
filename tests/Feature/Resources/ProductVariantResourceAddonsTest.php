<?php

use App\Enums\Addon\AddonGroupSelectionTypeEnum;
use App\Enums\Addon\AddonGroupStatusEnum;
use App\Http\Resources\Product\ProductVariantResource;
use App\Models\AddonGroup;
use App\Models\AddonItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Seller;
use App\Models\Store;
use App\Models\StoreAddonItem;
use App\Models\StoreProductVariant;
use App\Models\StoreProductVariantAddon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

uses(RefreshDatabase::class);

beforeEach(function () {
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
        'sku'                => 'SKU-A',
        'price'              => 10,
        'special_price'      => 10,
        'cost'               => 5,
        'stock'              => 100,
    ]);

    $this->group = AddonGroup::create([
        'seller_id'      => $this->seller->id,
        'title'          => 'Toppings',
        'selection_type' => AddonGroupSelectionTypeEnum::MULTIPLE->value,
        'status'         => AddonGroupStatusEnum::ACTIVE->value,
    ]);

    $this->item = AddonItem::create([
        'addon_group_id' => $this->group->id,
        'title'          => 'Extra cheese',
        'price'          => 1.50,
        'status'         => AddonGroupStatusEnum::ACTIVE->value,
    ]);
});

/**
 * Render the ProductVariantResource with the exact eager-loads the listing
 * pipelines perform (see Product::withVariantRelations and FeaturedSectionResource).
 */
function renderVariant(ProductVariant $variant): array
{
    $variant->load([
        'storeProductVariants.store',
        'storeVariantAddons.addonGroup',
        'storeVariantAddons.addonItem',
    ]);

    return (new ProductVariantResource($variant))->toArray(Request::create('/'));
}

it('renders attached addon using catalog defaults when no store_addon_items row exists', function () {
    StoreProductVariantAddon::create([
        'store_id'           => $this->store->id,
        'product_variant_id' => $this->variant->id,
        'addon_group_id'     => $this->group->id,
        'addon_item_id'      => $this->item->id,
    ]);

    $rendered = renderVariant($this->variant);

    expect($rendered['addon_groups'])->toHaveCount(1);

    $group = $rendered['addon_groups'][0];
    expect((int) $group['id'])->toBe($this->group->id)
        ->and($group['items'])->toHaveCount(1);

    $itemOut = $group['items'][0];
    expect((int) $itemOut['id'])->toBe($this->item->id)
        ->and((float) $itemOut['price'])->toBe(1.50)
        ->and((int) $itemOut['stock'])->toBe(0)
        ->and($itemOut['is_available'])->toBeTrue();
});

it('prefers store-level inventory values over catalog defaults when both are present', function () {
    StoreProductVariantAddon::create([
        'store_id'           => $this->store->id,
        'product_variant_id' => $this->variant->id,
        'addon_group_id'     => $this->group->id,
        'addon_item_id'      => $this->item->id,
    ]);

    StoreAddonItem::create([
        'store_id'      => $this->store->id,
        'addon_item_id' => $this->item->id,
        'price'         => 4.00,
        'cost'          => 1.00,
        'stock'         => 25,
        'is_available'  => true,
    ]);

    $rendered = renderVariant($this->variant);

    expect($rendered['addon_groups'])->toHaveCount(1);

    $itemOut = $rendered['addon_groups'][0]['items'][0];
    expect((float) $itemOut['price'])->toBe(4.00)
        ->and((float) $itemOut['cost'])->toBe(1.00)
        ->and((int) $itemOut['stock'])->toBe(25)
        ->and($itemOut['is_available'])->toBeTrue();
});

it('hides addon when store_addon_items row exists but is_available is false', function () {
    StoreProductVariantAddon::create([
        'store_id'           => $this->store->id,
        'product_variant_id' => $this->variant->id,
        'addon_group_id'     => $this->group->id,
        'addon_item_id'      => $this->item->id,
    ]);

    StoreAddonItem::create([
        'store_id'      => $this->store->id,
        'addon_item_id' => $this->item->id,
        'price'         => 4.00,
        'cost'          => 1.00,
        'stock'         => 50,
        'is_available'  => false,
    ]);

    $rendered = renderVariant($this->variant);

    expect($rendered['addon_groups'])->toBe([]);
});

it('hides addon when catalog is_available is false and no store_addon_items row exists', function () {
    $this->item->update(['is_available' => false]);

    StoreProductVariantAddon::create([
        'store_id'           => $this->store->id,
        'product_variant_id' => $this->variant->id,
        'addon_group_id'     => $this->group->id,
        'addon_item_id'      => $this->item->id,
    ]);

    $rendered = renderVariant($this->variant);

    expect($rendered['addon_groups'])->toBe([]);
});
