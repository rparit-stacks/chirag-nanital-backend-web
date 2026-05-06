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
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user   = User::factory()->create(['access_panel' => GuardNameEnum::SELLER->value]);
    $this->seller = Seller::factory()->create(['user_id' => $this->user->id]);

    Role::findOrCreate(DefaultSystemRolesEnum::SELLER->value, GuardNameEnum::SELLER->value);
    $this->user->assignRole(DefaultSystemRolesEnum::SELLER->value);

    $this->actingAs($this->user, GuardNameEnum::SELLER->value);

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
        'price'          => 1.5,
        'status'         => AddonGroupStatusEnum::ACTIVE->value,
    ]);
});

it('creates a store addon inventory row from the panel', function () {
    $this->postJson(route('seller.store-addon-items.store'), [
        'store_id'      => $this->store->id,
        'addon_item_id' => $this->item->id,
        'price'         => 25,
        'cost'          => 10,
        'stock'         => 50,
        'is_available'  => true,
    ])->assertCreated()
      ->assertJsonPath('success', true);

    expect(StoreAddonItem::where('store_id', $this->store->id)
        ->where('addon_item_id', $this->item->id)
        ->count())->toBe(1);
});

it('returns the row payload for the edit modal', function () {
    $row = StoreAddonItem::create([
        'store_id'      => $this->store->id,
        'addon_item_id' => $this->item->id,
        'price'         => 30,
        'stock'         => 10,
        'is_available'  => true,
    ]);

    $this->getJson(route('seller.store-addon-items.show', $row->id))
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.id', $row->id)
        ->assertJsonPath('data.store_id', $this->store->id)
        ->assertJsonPath('data.addon_item_id', $this->item->id)
        ->assertJsonPath('data.addon_group_id', $this->group->id)
        ->assertJsonPath('data.price', 30.0)
        ->assertJsonPath('data.is_available', true);
});

it('updates the row through the panel update endpoint', function () {
    $row = StoreAddonItem::create([
        'store_id'      => $this->store->id,
        'addon_item_id' => $this->item->id,
        'price'         => 30,
        'stock'         => 10,
        'is_available'  => true,
    ]);

    $this->postJson(route('seller.store-addon-items.update', $row->id), [
        'store_id'      => $this->store->id,
        'addon_item_id' => $this->item->id,
        'price'         => 40,
        'stock'         => 7,
        'is_available'  => false,
    ])->assertOk()
      ->assertJsonPath('success', true);

    $row->refresh();
    expect((float) $row->price)->toBe(40.0)
        ->and((int) $row->stock)->toBe(7)
        ->and((bool) $row->is_available)->toBeFalse();
});

it('soft deletes the row through the panel destroy endpoint', function () {
    $row = StoreAddonItem::create([
        'store_id'      => $this->store->id,
        'addon_item_id' => $this->item->id,
        'price'         => 30,
        'stock'         => 10,
        'is_available'  => true,
    ]);

    $this->deleteJson(route('seller.store-addon-items.delete', $row->id))
        ->assertOk()
        ->assertJsonPath('success', true);

    expect(StoreAddonItem::find($row->id))->toBeNull()
        ->and(StoreAddonItem::withTrashed()->find($row->id))->not->toBeNull();
});

it('returns the addon items lookup for a seller-owned group', function () {
    $this->getJson(route('seller.store-addon-items.items-for-group', $this->group->id))
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.0.id', $this->item->id);
});

it('returns price and cost in the items-for-group lookup so the UI can prefill defaults', function () {
    $this->item->update(['price' => 4.75, 'cost' => 2.10]);

    $this->getJson(route('seller.store-addon-items.items-for-group', $this->group->id))
        ->assertOk()
        ->assertJsonPath('data.0.id', $this->item->id)
        ->assertJsonPath('data.0.price', '4.75')
        ->assertJsonPath('data.0.cost', '2.10');
});

it('bulk-creates store addon items for a single store through the panel bulk endpoint', function () {
    $second = AddonItem::create([
        'addon_group_id' => $this->group->id,
        'title'          => 'Olives',
        'price'          => 2.00,
        'status'         => AddonGroupStatusEnum::ACTIVE->value,
    ]);

    $this->postJson(route('seller.store-addon-items.bulk-store'), [
        'store_ids' => [$this->store->id],
        'items'     => [
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
    ])
        ->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.saved', 2)
        ->assertJsonPath('data.skipped', 0);

    expect(StoreAddonItem::where('store_id', $this->store->id)->count())->toBe(2);
});

it('broadcasts bulk create across multiple stores in a single submission', function () {
    $store2 = Store::forceCreate([
        'seller_id' => $this->seller->id,
        'name'      => 'Uptown',
    ]);
    $second = AddonItem::create([
        'addon_group_id' => $this->group->id,
        'title'          => 'Olives',
        'price'          => 2.00,
        'status'         => AddonGroupStatusEnum::ACTIVE->value,
    ]);

    $this->postJson(route('seller.store-addon-items.bulk-store'), [
        'store_ids' => [$this->store->id, $store2->id],
        'items'     => [
            ['addon_item_id' => $this->item->id, 'price' => 25, 'stock' => 50, 'is_available' => true],
            ['addon_item_id' => $second->id,      'price' => 15, 'stock' => 20, 'is_available' => true],
        ],
    ])
        ->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.saved', 4)
        ->assertJsonPath('data.skipped', 0);

    expect(StoreAddonItem::count())->toBe(4)
        ->and(StoreAddonItem::where('store_id', $this->store->id)->count())->toBe(2)
        ->and(StoreAddonItem::where('store_id', $store2->id)->count())->toBe(2);
});

it('still accepts the legacy single store_id field on the bulk endpoint', function () {
    $this->postJson(route('seller.store-addon-items.bulk-store'), [
        'store_id' => $this->store->id,
        'items'    => [
            ['addon_item_id' => $this->item->id, 'price' => 25, 'is_available' => true],
        ],
    ])
        ->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.saved', 1);
});

it('rejects bulk create with a duplicate addon_item_id inside the payload', function () {
    $this->postJson(route('seller.store-addon-items.bulk-store'), [
        'store_ids' => [$this->store->id],
        'items'    => [
            ['addon_item_id' => $this->item->id, 'price' => 25, 'is_available' => true],
            ['addon_item_id' => $this->item->id, 'price' => 30, 'is_available' => true],
        ],
    ])->assertStatus(422);
});

it('rejects bulk create without any stores selected', function () {
    $this->postJson(route('seller.store-addon-items.bulk-store'), [
        'store_ids' => [],
        'items'    => [
            ['addon_item_id' => $this->item->id, 'price' => 25, 'is_available' => true],
        ],
    ])->assertStatus(422);
});

it('returns a matrix of existing inventory for the bulk form prefill', function () {
    $store2 = Store::forceCreate([
        'seller_id' => $this->seller->id,
        'name'      => 'Uptown',
    ]);

    StoreAddonItem::create([
        'store_id'      => $this->store->id,
        'addon_item_id' => $this->item->id,
        'price'         => 30,
        'stock'         => 10,
        'is_available'  => true,
    ]);

    $this->getJson(route('seller.store-addon-items.state-matrix', [
        'store_ids'      => [$this->store->id, $store2->id],
        'addon_item_ids' => [$this->item->id],
    ]))
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath("data.matrix.{$this->store->id}.{$this->item->id}.price", 30.0)
        ->assertJsonMissingPath("data.matrix.{$store2->id}.{$this->item->id}");
});

it('returns exists=false from the state lookup when the pair is not yet stocked', function () {
    $this->getJson(route('seller.store-addon-items.state', [
        'store_id'      => $this->store->id,
        'addon_item_id' => $this->item->id,
    ]))
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.exists', false);
});

it('returns the existing inventory state for a stocked pair so the bulk form can prefill it', function () {
    $row = StoreAddonItem::create([
        'store_id'      => $this->store->id,
        'addon_item_id' => $this->item->id,
        'price'         => 18.5,
        'cost'          => 5.25,
        'stock'         => 12,
        'is_available'  => true,
    ]);

    $this->getJson(route('seller.store-addon-items.state', [
        'store_id'      => $this->store->id,
        'addon_item_id' => $this->item->id,
    ]))
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.exists', true)
        ->assertJsonPath('data.id', $row->id)
        ->assertJsonPath('data.price', 18.5)
        ->assertJsonPath('data.cost', 5.25)
        ->assertJsonPath('data.stock', 12)
        ->assertJsonPath('data.is_available', true);
});

it('rejects state lookup for a store owned by another seller', function () {
    $otherSeller = Seller::factory()->create();
    $otherStore  = Store::forceCreate([
        'seller_id' => $otherSeller->id,
        'name'      => 'Other',
    ]);

    $this->getJson(route('seller.store-addon-items.state', [
        'store_id'      => $otherStore->id,
        'addon_item_id' => $this->item->id,
    ]))->assertStatus(422);
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

    $this->getJson(route('seller.store-addon-items.state', [
        'store_id'      => $this->store->id,
        'addon_item_id' => $foreignItem->id,
    ]))->assertStatus(422);
});
