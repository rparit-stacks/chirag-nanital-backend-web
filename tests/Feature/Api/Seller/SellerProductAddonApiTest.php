<?php

use App\Enums\Addon\AddonGroupSelectionTypeEnum;
use App\Enums\Addon\AddonGroupStatusEnum;
use App\Enums\DefaultSystemRolesEnum;
use App\Enums\GuardNameEnum;
use App\Models\AddonGroup;
use App\Models\AddonItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Seller;
use App\Models\Store;
use App\Models\StoreAddonItem;
use App\Models\StoreProductVariant;
use App\Models\StoreProductVariantAddon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Seller-auth (same pattern as SellerAddonGroupApiTest).
    $this->user   = User::factory()->create(['access_panel' => GuardNameEnum::SELLER->value]);
    $this->seller = Seller::factory()->create(['user_id' => $this->user->id]);

    Role::findOrCreate(DefaultSystemRolesEnum::SELLER->value, GuardNameEnum::SELLER->value);
    $this->user->assignRole(DefaultSystemRolesEnum::SELLER->value);

    Sanctum::actingAs($this->user);

    // Two stores, one product/variant, one addon group + two items.
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

it('bulk attaches (variant × group) and persists per-store rows', function () {
    $response = $this->postJson('/api/seller/product-addons', [
        'attachments' => [
            [
                'product_variant_id' => $this->variant->id,
                'addon_group_id'     => $this->group->id,
                'stores' => [
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
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $response->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.saved', 1)
        ->assertJsonPath('data.skipped', 0);

    expect(StoreProductVariantAddon::count())->toBe(3);
});

it('lists distinct (variant × group) attachments for the seller', function () {
    StoreProductVariantAddon::forceCreate([
        'product_variant_id' => $this->variant->id,
        'addon_group_id'     => $this->group->id,
        'addon_item_id'      => $this->itemCheese->id,
        'store_id'           => $this->store1->id,
    ]);

    $this->getJson('/api/seller/product-addons')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.data.0.product_variant_id', $this->variant->id)
        ->assertJsonPath('data.data.0.addon_group_id', $this->group->id);
});

it('returns the matrix payload on show for a single pair', function () {
    StoreProductVariantAddon::forceCreate([
        'product_variant_id' => $this->variant->id,
        'addon_group_id'     => $this->group->id,
        'addon_item_id'      => $this->itemCheese->id,
        'store_id'           => $this->store1->id,
    ]);

    StoreAddonItem::create([
        'store_id'      => $this->store1->id,
        'addon_item_id' => $this->itemCheese->id,
        'price'         => 30,
        'cost'          => 12,
        'stock'         => 100,
        'is_available'  => true,
    ]);

    $this->postJson('/api/seller/product-addons/show', [
        'pairs' => [
            ['variant_id' => $this->variant->id, 'group_id' => $this->group->id],
        ],
    ])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonCount(1, 'data.matrices')
        ->assertJsonCount(0, 'data.not_found')
        ->assertJsonStructure([
            'data' => [
                'matrices' => [
                    [
                        'variant' => ['id', 'title'],
                        'group'   => ['id', 'title'],
                        'stores',
                        'items',
                        'existing',
                        'inventory',
                    ],
                ],
                'not_found',
            ],
        ]);
});

it('returns matrices for multiple (variant, group) pairs in a single call', function () {
    // Second group + item so we can request two distinct matrices together.
    $group2 = AddonGroup::create([
        'seller_id'      => $this->seller->id,
        'title'          => 'Sauces',
        'selection_type' => AddonGroupSelectionTypeEnum::MULTIPLE->value,
        'status'         => AddonGroupStatusEnum::ACTIVE->value,
    ]);
    $itemKetchup = AddonItem::create([
        'addon_group_id' => $group2->id,
        'title'          => 'Ketchup',
        'price'          => 0.50,
        'status'         => AddonGroupStatusEnum::ACTIVE->value,
    ]);

    StoreProductVariantAddon::forceCreate([
        'product_variant_id' => $this->variant->id,
        'addon_group_id'     => $this->group->id,
        'addon_item_id'      => $this->itemCheese->id,
        'store_id'           => $this->store1->id,
    ]);
    StoreProductVariantAddon::forceCreate([
        'product_variant_id' => $this->variant->id,
        'addon_group_id'     => $group2->id,
        'addon_item_id'      => $itemKetchup->id,
        'store_id'           => $this->store2->id,
    ]);

    $response = $this->postJson('/api/seller/product-addons/show', [
        'pairs' => [
            ['variant_id' => $this->variant->id, 'group_id' => $this->group->id],
            ['variant_id' => $this->variant->id, 'group_id' => $group2->id],
        ],
    ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonCount(2, 'data.matrices')
        ->assertJsonCount(0, 'data.not_found')
        ->assertJsonPath('data.matrices.0.group.id', $this->group->id)
        ->assertJsonPath('data.matrices.1.group.id', $group2->id);
});

it('reports pairs with a non-owned variant or group under not_found without failing the request', function () {
    $otherSeller  = Seller::factory()->create();
    $otherProduct = Product::forceCreate([
        'seller_id' => $otherSeller->id,
        'title'     => 'Foreign Pizza',
    ]);
    $otherVariant = ProductVariant::forceCreate([
        'product_id' => $otherProduct->id,
        'title'      => 'Foreign',
    ]);

    $response = $this->postJson('/api/seller/product-addons/show', [
        'pairs' => [
            ['variant_id' => $this->variant->id, 'group_id' => $this->group->id],
            ['variant_id' => $otherVariant->id,  'group_id' => $this->group->id],
        ],
    ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonCount(1, 'data.matrices')
        ->assertJsonCount(1, 'data.not_found')
        ->assertJsonPath('data.matrices.0.variant.id', $this->variant->id)
        ->assertJsonPath('data.not_found.0.variant_id', $otherVariant->id)
        ->assertJsonPath('data.not_found.0.group_id', $this->group->id);
});

it('validates that pairs is required on show', function () {
    $this->postJson('/api/seller/product-addons/show', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['pairs']);
});

it('updates an attachment and removes stores where apply is false', function () {
    // Seed both stores first.
    StoreProductVariantAddon::forceCreate([
        'product_variant_id' => $this->variant->id,
        'addon_group_id'     => $this->group->id,
        'addon_item_id'      => $this->itemCheese->id,
        'store_id'           => $this->store1->id,
    ]);
    StoreProductVariantAddon::forceCreate([
        'product_variant_id' => $this->variant->id,
        'addon_group_id'     => $this->group->id,
        'addon_item_id'      => $this->itemCheese->id,
        'store_id'           => $this->store2->id,
    ]);

    // Keep store1, drop store2.
    $this->postJson("/api/seller/product-addons/{$this->variant->id}/{$this->group->id}", [
        'product_variant_id' => $this->variant->id,
        'addon_group_id'     => $this->group->id,
        'stores' => [
            ['store_id' => $this->store1->id, 'apply' => true, 'items' => [
                ['addon_item_id' => $this->itemCheese->id],
            ]],
            ['store_id' => $this->store2->id, 'apply' => false, 'items' => []],
        ],
    ])
        ->assertOk()
        ->assertJsonPath('success', true);

    expect(StoreProductVariantAddon::count())->toBe(1)
        ->and(StoreProductVariantAddon::where('store_id', $this->store2->id)->exists())->toBeFalse();
});

it('detaches the full (variant × group) attachment', function () {
    StoreProductVariantAddon::forceCreate([
        'product_variant_id' => $this->variant->id,
        'addon_group_id'     => $this->group->id,
        'addon_item_id'      => $this->itemCheese->id,
        'store_id'           => $this->store1->id,
    ]);
    StoreProductVariantAddon::forceCreate([
        'product_variant_id' => $this->variant->id,
        'addon_group_id'     => $this->group->id,
        'addon_item_id'      => $this->itemPepp->id,
        'store_id'           => $this->store2->id,
    ]);

    $this->deleteJson("/api/seller/product-addons/{$this->variant->id}/{$this->group->id}")
        ->assertOk()
        ->assertJsonPath('success', true);

    expect(StoreProductVariantAddon::count())->toBe(0);
});

it('returns 404 when updating a variant not owned by the seller', function () {
    $otherSeller = Seller::factory()->create();
    $otherProduct = Product::forceCreate([
        'seller_id' => $otherSeller->id,
        'title'     => 'Foreign Pizza',
    ]);
    $otherVariant = ProductVariant::forceCreate([
        'product_id' => $otherProduct->id,
        'title'      => 'Foreign',
    ]);

    $this->postJson("/api/seller/product-addons/{$otherVariant->id}/{$this->group->id}", [
        'product_variant_id' => $otherVariant->id,
        'addon_group_id'     => $this->group->id,
        'stores' => [
            ['store_id' => $this->store1->id, 'apply' => true, 'items' => [
                ['addon_item_id' => $this->itemCheese->id],
            ]],
        ],
    ])->assertStatus(404);
});

it('returns the product lookup list scoped to the seller', function () {
    $this->getJson('/api/seller/product-addons/lookup/products?search=Pizza')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.0.id', $this->product->id)
        ->assertJsonPath('data.0.title', 'Pizza');
});

it('returns variants for a seller-owned product', function () {
    $this->getJson("/api/seller/product-addons/lookup/products/{$this->product->id}/variants")
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.0.id', $this->variant->id)
        ->assertJsonPath('data.0.product_id', $this->product->id);
});

it('returns active addon groups for the lookup endpoint', function () {
    $this->getJson('/api/seller/product-addons/lookup/addon-groups')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.0.id', $this->group->id)
        ->assertJsonPath('data.0.title', 'Toppings');
});

it('returns the matrix via the dedicated matrix endpoint', function () {
    $this->getJson("/api/seller/product-addons/matrix/{$this->variant->id}/{$this->group->id}")
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'data' => [
                'variant' => ['id', 'title'],
                'group'   => ['id', 'title'],
                'stores',
                'items',
                'existing',
                'inventory',
            ],
        ]);
});
