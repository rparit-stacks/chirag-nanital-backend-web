<?php

use App\Enums\Addon\AddonGroupSelectionTypeEnum;
use App\Enums\Addon\AddonGroupStatusEnum;
use App\Enums\DefaultSystemRolesEnum;
use App\Enums\GuardNameEnum;
use App\Models\AddonGroup;
use App\Models\AddonItem;
use App\Models\Seller;
use App\Models\Store;
use App\Models\StoreAddonItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user   = User::factory()->create(['access_panel' => GuardNameEnum::SELLER->value]);
    $this->seller = Seller::factory()->create(['user_id' => $this->user->id]);

    Role::findOrCreate(DefaultSystemRolesEnum::SELLER->value, GuardNameEnum::SELLER->value);
    $this->user->assignRole(DefaultSystemRolesEnum::SELLER->value);

    Sanctum::actingAs($this->user);

    $this->store = Store::forceCreate([
        'seller_id' => $this->seller->id,
        'name'      => 'Downtown',
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

it('lists store addon inventory rows owned by the seller', function () {
    StoreAddonItem::create([
        'store_id'      => $this->store->id,
        'addon_item_id' => $this->item->id,
        'price'         => 30,
        'stock'         => 10,
        'is_available'  => true,
    ]);

    $this->getJson('/api/seller/store-addon-items')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.data.0.store_id', $this->store->id)
        ->assertJsonPath('data.data.0.addon_item_id', $this->item->id);
});

it('filters listing by addon_group_id', function () {
    // In-group row.
    StoreAddonItem::create([
        'store_id'      => $this->store->id,
        'addon_item_id' => $this->item->id,
        'price'         => 30,
        'stock'         => 10,
        'is_available'  => true,
    ]);

    // Different group, same seller.
    $otherGroup = AddonGroup::create([
        'seller_id'      => $this->seller->id,
        'title'          => 'Sauces',
        'selection_type' => AddonGroupSelectionTypeEnum::SINGLE->value,
        'status'         => AddonGroupStatusEnum::ACTIVE->value,
    ]);
    $otherItem = AddonItem::create([
        'addon_group_id' => $otherGroup->id,
        'title'          => 'Garlic',
        'price'          => 0.5,
        'status'         => AddonGroupStatusEnum::ACTIVE->value,
    ]);
    StoreAddonItem::create([
        'store_id'      => $this->store->id,
        'addon_item_id' => $otherItem->id,
        'price'         => 5,
        'stock'         => 10,
        'is_available'  => true,
    ]);

    $this->getJson('/api/seller/store-addon-items?addon_group_id=' . $this->group->id)
        ->assertOk()
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.data.0.addon_group_id', $this->group->id);
});

it('does not list rows belonging to another seller', function () {
    $otherSeller = Seller::factory()->create();
    $otherStore  = Store::forceCreate([
        'seller_id' => $otherSeller->id,
        'name'      => 'Other',
    ]);
    $otherGroup = AddonGroup::create([
        'seller_id'      => $otherSeller->id,
        'title'          => 'Foreign toppings',
        'selection_type' => AddonGroupSelectionTypeEnum::MULTIPLE->value,
        'status'         => AddonGroupStatusEnum::ACTIVE->value,
    ]);
    $otherItem = AddonItem::create([
        'addon_group_id' => $otherGroup->id,
        'title'          => 'Foreign cheese',
        'price'          => 2,
        'status'         => AddonGroupStatusEnum::ACTIVE->value,
    ]);
    StoreAddonItem::create([
        'store_id'      => $otherStore->id,
        'addon_item_id' => $otherItem->id,
        'price'         => 5,
        'stock'         => 10,
        'is_available'  => true,
    ]);

    $this->getJson('/api/seller/store-addon-items')
        ->assertOk()
        ->assertJsonPath('data.total', 0);
});

it('creates a store addon item via the API', function () {
    $response = $this->postJson('/api/seller/store-addon-items', [
        'store_id'      => $this->store->id,
        'addon_item_id' => $this->item->id,
        'price'         => 25,
        'cost'          => 10,
        'stock'         => 50,
        'is_available'  => true,
    ]);

    $response->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.price', 25.0)
        ->assertJsonPath('data.stock', 50);

    expect(StoreAddonItem::where('store_id', $this->store->id)
        ->where('addon_item_id', $this->item->id)
        ->count())->toBe(1);
});

it('rejects creating a row for an addon item that belongs to another seller', function () {
    $otherSeller = Seller::factory()->create();
    $foreignGroup = AddonGroup::create([
        'seller_id'      => $otherSeller->id,
        'title'          => 'Foreign',
        'selection_type' => AddonGroupSelectionTypeEnum::SINGLE->value,
        'status'         => AddonGroupStatusEnum::ACTIVE->value,
    ]);
    $foreignItem = AddonItem::create([
        'addon_group_id' => $foreignGroup->id,
        'title'          => 'Foreign cheese',
        'price'          => 1,
        'status'         => AddonGroupStatusEnum::ACTIVE->value,
    ]);

    $this->postJson('/api/seller/store-addon-items', [
        'store_id'      => $this->store->id,
        'addon_item_id' => $foreignItem->id,
        'price'         => 25,
    ])->assertStatus(422); // exists rule scoped to seller's groups blocks it
});

it('updates an existing store addon item', function () {
    $row = StoreAddonItem::create([
        'store_id'      => $this->store->id,
        'addon_item_id' => $this->item->id,
        'price'         => 20,
        'stock'         => 10,
        'is_available'  => true,
    ]);

    $this->postJson("/api/seller/store-addon-items/{$row->id}", [
        'store_id'      => $this->store->id,
        'addon_item_id' => $this->item->id,
        'price'         => 32,
        'stock'         => 5,
        'is_available'  => false,
    ])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.price', 32.0)
        ->assertJsonPath('data.stock', 5)
        ->assertJsonPath('data.is_available', false);

    $row->refresh();
    expect((float) $row->price)->toBe(32.0)
        ->and((int) $row->stock)->toBe(5)
        ->and((bool) $row->is_available)->toBeFalse();
});

it('soft deletes an owned store addon item', function () {
    $row = StoreAddonItem::create([
        'store_id'      => $this->store->id,
        'addon_item_id' => $this->item->id,
        'price'         => 20,
        'stock'         => 10,
        'is_available'  => true,
    ]);

    $this->deleteJson("/api/seller/store-addon-items/{$row->id}")
        ->assertOk()
        ->assertJsonPath('success', true);

    expect(StoreAddonItem::find($row->id))->toBeNull()
        ->and(StoreAddonItem::withTrashed()->find($row->id))->not->toBeNull();
});

it('rejects deleting a row owned by another seller', function () {
    $otherSeller = Seller::factory()->create();
    $otherStore  = Store::forceCreate([
        'seller_id' => $otherSeller->id,
        'name'      => 'Other',
    ]);
    $otherGroup = AddonGroup::create([
        'seller_id'      => $otherSeller->id,
        'title'          => 'Foreign',
        'selection_type' => AddonGroupSelectionTypeEnum::SINGLE->value,
        'status'         => AddonGroupStatusEnum::ACTIVE->value,
    ]);
    $otherItem = AddonItem::create([
        'addon_group_id' => $otherGroup->id,
        'title'          => 'Foreign cheese',
        'price'          => 1,
        'status'         => AddonGroupStatusEnum::ACTIVE->value,
    ]);
    $foreign = StoreAddonItem::create([
        'store_id'      => $otherStore->id,
        'addon_item_id' => $otherItem->id,
        'price'         => 5,
        'stock'         => 10,
        'is_available'  => true,
    ]);

    $this->deleteJson("/api/seller/store-addon-items/{$foreign->id}")
        ->assertStatus(404);

    expect(StoreAddonItem::find($foreign->id))->not->toBeNull();
});

it('returns the seller stores via the lookup endpoint', function () {
    $this->getJson('/api/seller/store-addon-items/lookup/stores')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.0.id', $this->store->id);
});

it('returns the seller addon groups via the lookup endpoint', function () {
    $this->getJson('/api/seller/store-addon-items/lookup/addon-groups')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.0.id', $this->group->id);
});

it('returns addon items for a seller-owned group', function () {
    $this->getJson("/api/seller/store-addon-items/lookup/groups/{$this->group->id}/items")
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.0.id', $this->item->id);
});

it('rejects items lookup for a group owned by another seller', function () {
    $otherSeller = Seller::factory()->create();
    $foreignGroup = AddonGroup::create([
        'seller_id'      => $otherSeller->id,
        'title'          => 'Foreign',
        'selection_type' => AddonGroupSelectionTypeEnum::SINGLE->value,
        'status'         => AddonGroupStatusEnum::ACTIVE->value,
    ]);

    $this->getJson("/api/seller/store-addon-items/lookup/groups/{$foreignGroup->id}/items")
        ->assertStatus(404);
});

it('exposes price and cost in the items-for-group lookup so clients can prefill defaults', function () {
    // Mutate the seeded item to expose non-default price/cost and make sure they
    // come back through the lookup payload.
    $this->item->update(['price' => 3.25, 'cost' => 1.10]);

    $this->getJson("/api/seller/store-addon-items/lookup/groups/{$this->group->id}/items")
        ->assertOk()
        ->assertJsonPath('data.0.id', $this->item->id)
        ->assertJsonPath('data.0.price', '3.25')
        ->assertJsonPath('data.0.cost', '1.10');
});

it('bulk-creates store addon items via the API', function () {
    $second = AddonItem::create([
        'addon_group_id' => $this->group->id,
        'title'          => 'Olives',
        'price'          => 2.00,
        'status'         => AddonGroupStatusEnum::ACTIVE->value,
    ]);

    $response = $this->postJson('/api/seller/store-addon-items/bulk', [
        'store_id' => $this->store->id,
        'items'    => [
            [
                'addon_item_id' => $this->item->id,
                'price'         => 25,
                'cost'          => 10,
                'stock'         => 50,
                'is_available'  => true,
            ],
            [
                'addon_item_id' => $second->id,
                'price'         => 15,
                'stock'         => 20,
                'is_available'  => false,
            ],
        ],
    ]);

    $response->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.saved', 2)
        ->assertJsonPath('data.skipped', 0);

    expect(StoreAddonItem::where('store_id', $this->store->id)->count())->toBe(2);
});

it('rejects bulk create with duplicate addon_item_id within the same payload', function () {
    $this->postJson('/api/seller/store-addon-items/bulk', [
        'store_id' => $this->store->id,
        'items'    => [
            [
                'addon_item_id' => $this->item->id,
                'price'         => 25,
                'is_available'  => true,
            ],
            [
                'addon_item_id' => $this->item->id,
                'price'         => 30,
                'is_available'  => true,
            ],
        ],
    ])->assertStatus(422);
});

it('rejects bulk create for a store owned by another seller', function () {
    $otherSeller = Seller::factory()->create();
    $otherStore  = Store::forceCreate([
        'seller_id' => $otherSeller->id,
        'name'      => 'Other',
    ]);

    $this->postJson('/api/seller/store-addon-items/bulk', [
        'store_id' => $otherStore->id,
        'items'    => [[
            'addon_item_id' => $this->item->id,
            'price'         => 25,
            'is_available'  => true,
        ]],
    ])->assertStatus(422);
});

it('rejects bulk create when an addon_item belongs to another seller', function () {
    $otherSeller = Seller::factory()->create();
    $foreignGroup = AddonGroup::create([
        'seller_id'      => $otherSeller->id,
        'title'          => 'Foreign',
        'selection_type' => AddonGroupSelectionTypeEnum::SINGLE->value,
        'status'         => AddonGroupStatusEnum::ACTIVE->value,
    ]);
    $foreignItem = AddonItem::create([
        'addon_group_id' => $foreignGroup->id,
        'title'          => 'Foreign cheese',
        'price'          => 1,
        'status'         => AddonGroupStatusEnum::ACTIVE->value,
    ]);

    $this->postJson('/api/seller/store-addon-items/bulk', [
        'store_id' => $this->store->id,
        'items'    => [[
            'addon_item_id' => $foreignItem->id,
            'price'         => 25,
            'is_available'  => true,
        ]],
    ])->assertStatus(422);
});

it('returns exists=false from the state lookup when no row is stocked for the pair', function () {
    $this->getJson('/api/seller/store-addon-items/lookup/state?'
        . http_build_query(['store_id' => $this->store->id, 'addon_item_id' => $this->item->id]))
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.exists', false);
});

it('returns the existing inventory state when a row exists for the pair', function () {
    $row = StoreAddonItem::create([
        'store_id'      => $this->store->id,
        'addon_item_id' => $this->item->id,
        'price'         => 22.5,
        'cost'          => 7.25,
        'stock'         => 33,
        'is_available'  => true,
    ]);

    $this->getJson('/api/seller/store-addon-items/lookup/state?'
        . http_build_query(['store_id' => $this->store->id, 'addon_item_id' => $this->item->id]))
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.exists', true)
        ->assertJsonPath('data.id', $row->id)
        ->assertJsonPath('data.price', 22.5)
        ->assertJsonPath('data.cost', 7.25)
        ->assertJsonPath('data.stock', 33)
        ->assertJsonPath('data.is_available', true);
});

it('rejects state lookup for a store owned by another seller', function () {
    $otherSeller = Seller::factory()->create();
    $otherStore  = Store::forceCreate([
        'seller_id' => $otherSeller->id,
        'name'      => 'Other',
    ]);

    $this->getJson('/api/seller/store-addon-items/lookup/state?'
        . http_build_query(['store_id' => $otherStore->id, 'addon_item_id' => $this->item->id]))
        ->assertStatus(422);
});

it('rejects state lookup for an addon_item owned by another seller', function () {
    $otherSeller = Seller::factory()->create();
    $foreignGroup = AddonGroup::create([
        'seller_id'      => $otherSeller->id,
        'title'          => 'Foreign',
        'selection_type' => AddonGroupSelectionTypeEnum::SINGLE->value,
        'status'         => AddonGroupStatusEnum::ACTIVE->value,
    ]);
    $foreignItem = AddonItem::create([
        'addon_group_id' => $foreignGroup->id,
        'title'          => 'Foreign cheese',
        'price'          => 1,
        'status'         => AddonGroupStatusEnum::ACTIVE->value,
    ]);

    $this->getJson('/api/seller/store-addon-items/lookup/state?'
        . http_build_query(['store_id' => $this->store->id, 'addon_item_id' => $foreignItem->id]))
        ->assertStatus(422);
});
