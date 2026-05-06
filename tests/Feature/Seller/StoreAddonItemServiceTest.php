<?php

use App\Enums\Addon\AddonGroupSelectionTypeEnum;
use App\Enums\Addon\AddonGroupStatusEnum;
use App\Models\AddonGroup;
use App\Models\AddonItem;
use App\Models\Seller;
use App\Models\Store;
use App\Models\StoreAddonItem;
use App\Services\StoreAddonItemService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = app(StoreAddonItemService::class);
    $this->seller  = Seller::factory()->create();

    $this->store1 = Store::forceCreate([
        'seller_id' => $this->seller->id,
        'name'      => 'Downtown',
    ]);
    $this->store2 = Store::forceCreate([
        'seller_id' => $this->seller->id,
        'name'      => 'Uptown',
    ]);

    $this->group = AddonGroup::create([
        'seller_id'      => $this->seller->id,
        'title'          => 'Toppings',
        'selection_type' => AddonGroupSelectionTypeEnum::MULTIPLE->value,
        'status'         => AddonGroupStatusEnum::ACTIVE->value,
    ]);

    $this->cheese = AddonItem::create([
        'addon_group_id' => $this->group->id,
        'title'          => 'Extra cheese',
        'price'          => 1.50,
        'status'         => AddonGroupStatusEnum::ACTIVE->value,
    ]);
});

it('creates a store_addon_items row on first upsert', function () {
    $row = $this->service->upsert($this->store1, $this->cheese, [
        'price'        => 30,
        'cost'         => 12,
        'stock'        => 100,
        'is_available' => true,
    ]);

    expect(StoreAddonItem::count())->toBe(1)
        ->and((float) $row->price)->toBe(30.0)
        ->and((int) $row->stock)->toBe(100)
        ->and($row->is_available)->toBeTrue()
        ->and($row->store_id)->toBe($this->store1->id)
        ->and($row->addon_item_id)->toBe($this->cheese->id);
});

it('updates the existing row instead of creating a duplicate', function () {
    $this->service->upsert($this->store1, $this->cheese, [
        'price' => 30,
        'stock' => 100,
    ]);

    $this->service->upsert($this->store1, $this->cheese, [
        'price' => 35,
        'stock' => 80,
    ]);

    expect(StoreAddonItem::count())->toBe(1);

    $row = StoreAddonItem::where('store_id', $this->store1->id)
        ->where('addon_item_id', $this->cheese->id)
        ->first();

    expect((float) $row->price)->toBe(35.0)
        ->and((int) $row->stock)->toBe(80);
});

it('throws when creating without a price', function () {
    expect(fn () => $this->service->upsert($this->store1, $this->cheese, ['stock' => 10]))
        ->toThrow(InvalidArgumentException::class);
});

it('restores a soft-deleted row on upsert rather than violating the unique index', function () {
    $row = $this->service->upsert($this->store1, $this->cheese, [
        'price' => 30,
        'stock' => 100,
    ]);
    $this->service->delete($row);

    expect(StoreAddonItem::count())->toBe(0)
        ->and(StoreAddonItem::withTrashed()->count())->toBe(1);

    $restored = $this->service->upsert($this->store1, $this->cheese, [
        'price' => 40,
        'stock' => 50,
    ]);

    expect(StoreAddonItem::count())->toBe(1)
        ->and($restored->id)->toBe($row->id)
        ->and((float) $restored->price)->toBe(40.0);
});

it('rolls out a single addon item to multiple stores via bulkUpsert', function () {
    $result = $this->service->bulkUpsert($this->seller, $this->cheese, [
        ['store_id' => $this->store1->id, 'price' => 30, 'stock' => 100],
        ['store_id' => $this->store2->id, 'price' => 32, 'stock' => 80],
    ]);

    expect($result['saved'])->toBe(2)
        ->and($result['skipped'])->toBe(0)
        ->and(StoreAddonItem::count())->toBe(2);
});

it('skips stores not owned by the seller in bulkUpsert', function () {
    $otherSeller = Seller::factory()->create();
    $foreignStore = Store::forceCreate([
        'seller_id' => $otherSeller->id,
        'name'      => 'Other',
    ]);

    $result = $this->service->bulkUpsert($this->seller, $this->cheese, [
        ['store_id' => $this->store1->id, 'price' => 30, 'stock' => 100],
        ['store_id' => $foreignStore->id, 'price' => 30, 'stock' => 100],
    ]);

    expect($result['saved'])->toBe(1)
        ->and($result['skipped'])->toBe(1)
        ->and(StoreAddonItem::count())->toBe(1)
        ->and(StoreAddonItem::where('store_id', $foreignStore->id)->exists())->toBeFalse();
});

it('decrements stock atomically and refuses to go negative', function () {
    $row = $this->service->upsert($this->store1, $this->cheese, [
        'price' => 30,
        'stock' => 2,
    ]);

    expect($this->service->decrementStock($row, 1))->toBeTrue();
    $row->refresh();
    expect((int) $row->stock)->toBe(1);

    // Asking for 5 when only 1 remains must fail and leave the row untouched.
    expect($this->service->decrementStock($row, 5))->toBeFalse();
    $row->refresh();
    expect((int) $row->stock)->toBe(1);
});

it('increments stock on restock', function () {
    $row = $this->service->upsert($this->store1, $this->cheese, [
        'price' => 30,
        'stock' => 0,
    ]);

    expect($this->service->incrementStock($row, 25))->toBeTrue();
    $row->refresh();
    expect((int) $row->stock)->toBe(25);
});

it('treats isInStock as (available AND enough stock)', function () {
    $row = $this->service->upsert($this->store1, $this->cheese, [
        'price'        => 30,
        'stock'        => 3,
        'is_available' => true,
    ]);

    expect($row->isInStock(3))->toBeTrue()
        ->and($row->isInStock(4))->toBeFalse();

    $row = $this->service->upsert($this->store1, $this->cheese, [
        'is_available' => false,
    ]);
    expect($row->isInStock(1))->toBeFalse();
});

it('scopes queries to the seller across stores and groups', function () {
    $otherSeller = Seller::factory()->create();
    $otherStore  = Store::forceCreate([
        'seller_id' => $otherSeller->id,
        'name'      => 'Other',
    ]);
    $otherGroup = AddonGroup::create([
        'seller_id'      => $otherSeller->id,
        'title'          => 'Other toppings',
        'selection_type' => AddonGroupSelectionTypeEnum::MULTIPLE->value,
        'status'         => AddonGroupStatusEnum::ACTIVE->value,
    ]);
    $otherItem = AddonItem::create([
        'addon_group_id' => $otherGroup->id,
        'title'          => 'Foreign cheese',
        'price'          => 1.50,
        'status'         => AddonGroupStatusEnum::ACTIVE->value,
    ]);

    $this->service->upsert($this->store1, $this->cheese, ['price' => 30, 'stock' => 10]);
    $this->service->upsert($otherStore, $otherItem, ['price' => 30, 'stock' => 10]);

    expect(StoreAddonItem::count())->toBe(2)
        ->and($this->service->queryForSeller($this->seller)->count())->toBe(1)
        ->and($this->service->queryForSeller($otherSeller)->count())->toBe(1);
});

it('rejects upsert when addon item belongs to another seller', function () {
    $otherSeller = Seller::factory()->create();
    $otherGroup = AddonGroup::create([
        'seller_id'      => $otherSeller->id,
        'title'          => 'Other',
        'selection_type' => AddonGroupSelectionTypeEnum::MULTIPLE->value,
        'status'         => AddonGroupStatusEnum::ACTIVE->value,
    ]);
    $foreignItem = AddonItem::create([
        'addon_group_id' => $otherGroup->id,
        'title'          => 'Foreign item',
        'price'          => 1.50,
        'status'         => AddonGroupStatusEnum::ACTIVE->value,
    ]);

    expect(fn () => $this->service->upsert($this->store1, $foreignItem, ['price' => 30]))
        ->toThrow(DomainException::class);
});

it('narrows queryForSellerByGroup to a specific addon group', function () {
    $sauces = AddonGroup::create([
        'seller_id'      => $this->seller->id,
        'title'          => 'Sauces',
        'selection_type' => AddonGroupSelectionTypeEnum::SINGLE->value,
        'status'         => AddonGroupStatusEnum::ACTIVE->value,
    ]);
    $garlic = AddonItem::create([
        'addon_group_id' => $sauces->id,
        'title'          => 'Garlic',
        'price'          => 0.5,
        'status'         => AddonGroupStatusEnum::ACTIVE->value,
    ]);

    $this->service->upsert($this->store1, $this->cheese, ['price' => 30, 'stock' => 10]);
    $this->service->upsert($this->store1, $garlic, ['price' => 5, 'stock' => 10]);

    $rows = $this->service
        ->queryForSellerByGroup($this->seller, $this->group->id)
        ->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->addon_item_id)->toBe($this->cheese->id);
});

it('narrows queryForSellerByGroup to a specific store', function () {
    $this->service->upsert($this->store1, $this->cheese, ['price' => 30, 'stock' => 10]);
    $this->service->upsert($this->store2, $this->cheese, ['price' => 32, 'stock' => 8]);

    $rows = $this->service
        ->queryForSellerByGroup($this->seller, null, $this->store2->id)
        ->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->store_id)->toBe($this->store2->id);
});

it('broadcasts a shared item set across many stores via bulkUpsertAcrossStores', function () {
    $pepp = AddonItem::create([
        'addon_group_id' => $this->group->id,
        'title'          => 'Pepperoni',
        'price'          => 2.0,
        'status'         => AddonGroupStatusEnum::ACTIVE->value,
    ]);

    $result = $this->service->bulkUpsertAcrossStores(
        $this->seller,
        [$this->store1->id, $this->store2->id],
        [
            ['addon_item_id' => $this->cheese->id, 'price' => 30, 'stock' => 100, 'is_available' => true],
            ['addon_item_id' => $pepp->id,         'price' => 25, 'stock' => 50,  'is_available' => true],
        ],
    );

    expect($result['saved'])->toBe(4)
        ->and($result['skipped'])->toBe(0)
        ->and($result['saved_rows'])->toHaveCount(4)
        ->and($result['skipped_rows'])->toBe([])
        ->and(StoreAddonItem::count())->toBe(4)
        ->and(StoreAddonItem::where('store_id', $this->store1->id)->count())->toBe(2)
        ->and(StoreAddonItem::where('store_id', $this->store2->id)->count())->toBe(2);

    // Each saved_rows entry should have the store + addonItem relations preloaded
    // so a Resource transformer can expose store_name / addon_item_title without n+1.
    $first = $result['saved_rows']->first();
    expect($first->relationLoaded('store'))->toBeTrue()
        ->and($first->relationLoaded('addonItem'))->toBeTrue();
});

it('upserts in place when bulkUpsertAcrossStores re-hits an existing (store × item) row', function () {
    // Pre-seed an existing row at store1 for cheese.
    $this->service->upsert($this->store1, $this->cheese, ['price' => 10, 'stock' => 5]);

    $result = $this->service->bulkUpsertAcrossStores(
        $this->seller,
        [$this->store1->id, $this->store2->id],
        [
            ['addon_item_id' => $this->cheese->id, 'price' => 42, 'stock' => 77, 'is_available' => true],
        ],
    );

    expect($result['saved'])->toBe(2)
        ->and($result['skipped'])->toBe(0)
        ->and(StoreAddonItem::count())->toBe(2);

    $store1Row = StoreAddonItem::where('store_id', $this->store1->id)
        ->where('addon_item_id', $this->cheese->id)->first();
    expect((float) $store1Row->price)->toBe(42.0)
        ->and((int) $store1Row->stock)->toBe(77);
});

it('skips foreign stores / items in bulkUpsertAcrossStores', function () {
    $otherSeller = Seller::factory()->create();
    $foreignStore = Store::forceCreate([
        'seller_id' => $otherSeller->id,
        'name'      => 'Other',
    ]);
    $foreignGroup = AddonGroup::create([
        'seller_id'      => $otherSeller->id,
        'title'          => 'Other group',
        'selection_type' => AddonGroupSelectionTypeEnum::MULTIPLE->value,
        'status'         => AddonGroupStatusEnum::ACTIVE->value,
    ]);
    $foreignItem = AddonItem::create([
        'addon_group_id' => $foreignGroup->id,
        'title'          => 'Foreign',
        'price'          => 5.0,
        'status'         => AddonGroupStatusEnum::ACTIVE->value,
    ]);

    $result = $this->service->bulkUpsertAcrossStores(
        $this->seller,
        [$this->store1->id, $foreignStore->id],
        [
            ['addon_item_id' => $this->cheese->id, 'price' => 30],
            ['addon_item_id' => $foreignItem->id,  'price' => 30], // not owned
        ],
    );

    // Only (store1, cheese) is saved. Both legs of the foreign store are skipped,
    // and (store1, foreignItem) is skipped.
    expect($result['saved'])->toBe(1)
        ->and($result['skipped'])->toBe(3)
        ->and($result['saved_rows'])->toHaveCount(1)
        ->and($result['skipped_rows'])->toHaveCount(3)
        ->and(StoreAddonItem::count())->toBe(1)
        ->and(StoreAddonItem::where('store_id', $foreignStore->id)->exists())->toBeFalse();

    $reasons = collect($result['skipped_rows'])
        ->groupBy('reason')
        ->map->count()
        ->all();

    expect($reasons)->toBe([
        'store_not_owned'      => 2, // foreign store × (cheese, foreignItem)
        'addon_item_not_owned' => 1, // (store1, foreignItem)
    ]);
});

it('returns a matrix of existing rows keyed by [store_id][addon_item_id]', function () {
    $pepp = AddonItem::create([
        'addon_group_id' => $this->group->id,
        'title'          => 'Pepperoni',
        'price'          => 2.0,
        'status'         => AddonGroupStatusEnum::ACTIVE->value,
    ]);

    // store1 has both items, store2 only has cheese.
    $this->service->upsert($this->store1, $this->cheese, ['price' => 30, 'stock' => 10]);
    $this->service->upsert($this->store1, $pepp,         ['price' => 25, 'stock' => 6,  'is_available' => false]);
    $this->service->upsert($this->store2, $this->cheese, ['price' => 32, 'stock' => 8]);

    $matrix = $this->service->inventoryMatrix(
        $this->seller,
        [$this->store1->id, $this->store2->id],
        [$this->cheese->id, $pepp->id],
    );

    expect($matrix[$this->store1->id][$this->cheese->id]['price'])->toBe(30.0)
        ->and($matrix[$this->store1->id][$pepp->id]['stock'])->toBe(6)
        ->and($matrix[$this->store1->id][$pepp->id]['is_available'])->toBeFalse()
        ->and($matrix[$this->store2->id][$this->cheese->id]['stock'])->toBe(8)
        ->and($matrix[$this->store2->id][$pepp->id] ?? null)->toBeNull();
});

it('combines group + store filters in queryForSellerByGroup', function () {
    $sauces = AddonGroup::create([
        'seller_id'      => $this->seller->id,
        'title'          => 'Sauces',
        'selection_type' => AddonGroupSelectionTypeEnum::SINGLE->value,
        'status'         => AddonGroupStatusEnum::ACTIVE->value,
    ]);
    $garlic = AddonItem::create([
        'addon_group_id' => $sauces->id,
        'title'          => 'Garlic',
        'price'          => 0.5,
        'status'         => AddonGroupStatusEnum::ACTIVE->value,
    ]);

    $this->service->upsert($this->store1, $this->cheese, ['price' => 30, 'stock' => 10]);
    $this->service->upsert($this->store2, $this->cheese, ['price' => 32, 'stock' => 8]);
    $this->service->upsert($this->store1, $garlic,       ['price' => 5,  'stock' => 4]);
    $this->service->upsert($this->store2, $garlic,       ['price' => 6,  'stock' => 3]);

    $rows = $this->service
        ->queryForSellerByGroup($this->seller, $sauces->id, $this->store2->id)
        ->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->store_id)->toBe($this->store2->id)
        ->and($rows->first()->addon_item_id)->toBe($garlic->id);
});
