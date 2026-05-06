<?php

namespace App\Services;

use App\Models\AddonItem;
use App\Models\Seller;
use App\Models\Store;
use App\Models\StoreAddonItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Manage store-level pricing + inventory for addon items.
 *
 * One row per (store, addon_item) drives price/cost/stock/availability
 * regardless of how many product variants offer the addon. Consumed at
 * checkout for pricing and at order confirmation for stock decrement.
 */
class StoreAddonItemService
{
    /**
     * Seller-scoped base query — only rows belonging to the seller's own stores
     * and their own catalog addon items.
     */
    public function queryForSeller(Seller $seller): Builder
    {
        return StoreAddonItem::query()
            ->whereHas('store', fn (Builder $q) => $q->where('seller_id', $seller->id))
            ->whereHas('addonItem.group', fn (Builder $q) => $q->where('seller_id', $seller->id));
    }

    /**
     * All store-addon-item rows for a single store (seller-facing "inventory" listing).
     */
    public function queryForStore(Store $store): Builder
    {
        return StoreAddonItem::query()
            ->where('store_id', $store->id);
    }

    /**
     * Seller-scoped listing optionally narrowed to a specific addon group and/or store.
     * Powers the "Store Addon Inventory" panel screen.
     */
    public function queryForSellerByGroup(Seller $seller, ?int $groupId = null, ?int $storeId = null): Builder
    {
        $query = $this->queryForSeller($seller);

        if ($groupId !== null) {
            $query->whereHas('addonItem', fn (Builder $q) => $q->where('addon_group_id', $groupId));
        }

        if ($storeId !== null) {
            $query->where('store_id', $storeId);
        }

        return $query;
    }

    /**
     * Fetch the active row a checkout flow needs: (store, addon_item) tuple,
     * non-soft-deleted, currently available. Returns null when the addon is
     * not stocked or not available at that store.
     */
    public function findForCheckout(int $storeId, int $addonItemId): ?StoreAddonItem
    {
        return StoreAddonItem::query()
            ->where('store_id', $storeId)
            ->where('addon_item_id', $addonItemId)
            ->first();
    }

    /**
     * Find the (store, addon_item) row for a seller — used by the panel bulk
     * form to prefill existing price / cost / stock / is_available when the
     * seller picks an item that's already stocked at the chosen store.
     * Returns null when no row exists or the resource isn't owned by the seller.
     */
    public function findForSeller(Seller $seller, int $storeId, int $addonItemId): ?StoreAddonItem
    {
        return $this->queryForSeller($seller)
            ->where('store_id', $storeId)
            ->where('addon_item_id', $addonItemId)
            ->first();
    }

    /**
     * Create-or-update the single (store, addon_item) row.
     *
     * Soft-deleted rows are restored in place so the unique index is not violated.
     *
     * $attributes supports: price (required on create), cost, stock, is_available, metadata.
     */
    public function upsert(Store $store, AddonItem $item, array $attributes): StoreAddonItem
    {
        $this->assertSameSeller($store, $item);

        return DB::transaction(function () use ($store, $item, $attributes) {
            /** @var StoreAddonItem|null $row */
            $row = StoreAddonItem::withTrashed()
                ->where('store_id', $store->id)
                ->where('addon_item_id', $item->id)
                ->first();

            $payload = $this->normalizeAttributes($attributes, creating: $row === null);

            if ($row) {
                if ($row->trashed()) {
                    $row->restore();
                }
                $row->fill($payload)->save();

                return $row;
            }

            return StoreAddonItem::create(array_merge([
                'store_id'      => $store->id,
                'addon_item_id' => $item->id,
            ], $payload));
        });
    }

    /**
     * Roll out a single catalog addon item to many stores at once.
     *
     * $stores shape:
     *   [
     *     ['store_id' => 1, 'price' => 30, 'cost' => 12, 'stock' => 100, 'is_available' => true],
     *     ['store_id' => 2, 'price' => 32, 'stock' => 50],
     *     ...
     *   ]
     *
     * Stores not owned by the seller are silently skipped so a tampered payload
     * can't corrupt a neighbour's inventory. Returns saved / skipped counts.
     *
     * @return array{saved:int, skipped:int}
     */
    public function bulkUpsert(Seller $seller, AddonItem $item, array $stores): array
    {
        if ($item->group && $item->group->seller_id !== null && $item->group->seller_id !== $seller->id) {
            return ['saved' => 0, 'skipped' => count($stores)];
        }

        $saved = 0;
        $skipped = 0;

        DB::transaction(function () use ($seller, $item, $stores, &$saved, &$skipped) {
            $storeIds = collect($stores)->pluck('store_id')->filter()->map(fn ($id) => (int) $id)->unique()->all();

            $ownedStores = Store::query()
                ->whereIn('id', $storeIds)
                ->where('seller_id', $seller->id)
                ->get()
                ->keyBy('id');

            foreach ($stores as $row) {
                $storeId = (int) ($row['store_id'] ?? 0);
                $store = $ownedStores->get($storeId);

                if (! $store) {
                    $skipped++;
                    continue;
                }

                $this->upsert($store, $item, $row);
                $saved++;
            }
        });

        return ['saved' => $saved, 'skipped' => $skipped];
    }

    /**
     * Bulk-upsert the inventory for a single store: the user picks one store
     * and a list of (addon_item_id, price, cost, stock, is_available) rows
     * that should each become a store_addon_items row.
     *
     * $rows shape:
     *   [
     *     ['addon_item_id' => 10, 'price' => 30, 'cost' => 12, 'stock' => 100, 'is_available' => true],
     *     ['addon_item_id' => 11, 'price' => 32, 'stock' => 50],
     *     ...
     *   ]
     *
     * Items whose group isn't owned by the seller (or where the item can't be
     * found) are silently skipped so a tampered payload can't corrupt state.
     *
     * @return array{saved:int, skipped:int}
     */
    public function bulkUpsertForStore(Seller $seller, Store $store, array $rows): array
    {
        if ((int) $store->seller_id !== (int) $seller->id) {
            return ['saved' => 0, 'skipped' => count($rows)];
        }

        $saved = 0;
        $skipped = 0;

        DB::transaction(function () use ($seller, $store, $rows, &$saved, &$skipped) {
            $itemIds = collect($rows)->pluck('addon_item_id')->filter()->map(fn ($id) => (int) $id)->unique()->all();

            $ownedItems = AddonItem::query()
                ->whereIn('id', $itemIds)
                ->whereHas('group', fn ($q) => $q->where('seller_id', $seller->id))
                ->get()
                ->keyBy('id');

            foreach ($rows as $row) {
                $itemId = (int) ($row['addon_item_id'] ?? 0);
                $item = $ownedItems->get($itemId);

                if (! $item) {
                    $skipped++;
                    continue;
                }

                $this->upsert($store, $item, $row);
                $saved++;
            }
        });

        return ['saved' => $saved, 'skipped' => $skipped];
    }

    /**
     * Apply the same set of inventory rows to many stores at once.
     *
     * Payload shape — one flat list of items with shared (price, cost, stock,
     * is_available) settings, broadcast across every selected store:
     *   $storeIds = [1, 2, 3]
     *   $rows     = [
     *       ['addon_item_id' => 10, 'price' => 30, 'cost' => 12, 'stock' => 100, 'is_available' => true],
     *       ['addon_item_id' => 11, 'price' => 32, 'stock' => 50],
     *   ]
     *
     * Each (store × item) pair is upserted so existing inventory rows are
     * updated in place rather than duplicated. Stores that don't belong to the
     * seller (or items whose group isn't owned by the seller) are silently
     * skipped — the caller shouldn't be able to corrupt a neighbour's data
     * by tampering with the form payload.
     *
     * Returns both the aggregate counts and per-row details so callers can
     * surface exactly what was written and why the rest was dropped.
     *
     * Skip reason codes:
     *   - `store_not_owned`     — the store_id doesn't belong to the seller.
     *   - `addon_item_not_owned` — the addon_item_id isn't in a group owned by the seller.
     *
     * @return array{
     *     saved:int,
     *     skipped:int,
     *     saved_rows:\Illuminate\Support\Collection<int, StoreAddonItem>,
     *     skipped_rows:array<int, array{store_id:int, addon_item_id:?int, reason:string}>
     * }
     */
    public function bulkUpsertAcrossStores(Seller $seller, array $storeIds, array $rows): array
    {
        $storeIds = array_values(array_unique(array_filter(
            array_map(static fn ($id) => (int) $id, $storeIds),
        )));

        $empty = [
            'saved'        => 0,
            'skipped'      => 0,
            'saved_rows'   => collect(),
            'skipped_rows' => [],
        ];

        if (empty($storeIds) || empty($rows)) {
            return $empty;
        }

        $saved = 0;
        $skipped = 0;
        $savedRows = collect();
        $skippedRows = [];

        DB::transaction(function () use (
            $seller, $storeIds, $rows,
            &$saved, &$skipped, &$savedRows, &$skippedRows
        ) {
            $ownedStores = Store::query()
                ->whereIn('id', $storeIds)
                ->where('seller_id', $seller->id)
                ->get()
                ->keyBy('id');

            $itemIds = collect($rows)->pluck('addon_item_id')->filter()->map(fn ($id) => (int) $id)->unique()->all();

            $ownedItems = AddonItem::query()
                ->with('group')
                ->whereIn('id', $itemIds)
                ->whereHas('group', fn ($q) => $q->where('seller_id', $seller->id))
                ->get()
                ->keyBy('id');

            foreach ($storeIds as $storeId) {
                $store = $ownedStores->get($storeId);

                if (! $store) {
                    // Whole row of (this store × every item) is unreachable — skip each leg with a reason.
                    foreach ($rows as $row) {
                        $skipped++;
                        $skippedRows[] = [
                            'store_id'      => $storeId,
                            'addon_item_id' => isset($row['addon_item_id']) ? (int) $row['addon_item_id'] : null,
                            'reason'        => 'store_not_owned',
                        ];
                    }
                    continue;
                }

                foreach ($rows as $row) {
                    $itemId = (int) ($row['addon_item_id'] ?? 0);
                    $item   = $ownedItems->get($itemId);

                    if (! $item) {
                        $skipped++;
                        $skippedRows[] = [
                            'store_id'      => (int) $store->id,
                            'addon_item_id' => $itemId ?: null,
                            'reason'        => 'addon_item_not_owned',
                        ];
                        continue;
                    }

                    $upserted = $this->upsert($store, $item, $row);
                    // Attach the already-loaded relations so the Resource transformer
                    // doesn't need extra queries to expose store_name / addon_item_title.
                    $upserted->setRelation('store', $store);
                    $upserted->setRelation('addonItem', $item);
                    $saved++;
                    $savedRows->push($upserted);
                }
            }
        });

        return [
            'saved'        => $saved,
            'skipped'      => $skipped,
            'saved_rows'   => $savedRows,
            'skipped_rows' => $skippedRows,
        ];
    }

    /**
     * Look up existing inventory rows for a (stores × addon_items) matrix so
     * the bulk form can flag which cells will update an existing row and show
     * the current price / cost / stock / is_available.
     *
     * @return array<int, array<int, array{id:int, price:float, cost:float|null, stock:int, is_available:bool}>>
     *     Nested map: [store_id][addon_item_id] => row snapshot.
     */
    public function inventoryMatrix(Seller $seller, array $storeIds, array $addonItemIds): array
    {
        $storeIds = array_values(array_unique(array_filter(
            array_map(static fn ($id) => (int) $id, $storeIds),
        )));
        $addonItemIds = array_values(array_unique(array_filter(
            array_map(static fn ($id) => (int) $id, $addonItemIds),
        )));

        if (empty($storeIds) || empty($addonItemIds)) {
            return [];
        }

        $rows = $this->queryForSeller($seller)
            ->whereIn('store_id', $storeIds)
            ->whereIn('addon_item_id', $addonItemIds)
            ->get();

        $matrix = [];
        foreach ($rows as $row) {
            $matrix[(int) $row->store_id][(int) $row->addon_item_id] = [
                'id'           => (int) $row->id,
                'price'        => (float) $row->price,
                'cost'         => $row->cost !== null ? (float) $row->cost : null,
                'stock'        => (int) $row->stock,
                'is_available' => (bool) $row->is_available,
            ];
        }

        return $matrix;
    }

    /**
     * Soft delete the store inventory row. The underlying unique index still
     * treats the row as present; callers that want to replace must go through upsert().
     */
    public function delete(StoreAddonItem $row): void
    {
        $row->delete();
    }

    /**
     * Atomic stock decrement. Returns true when stock was reduced, false when
     * insufficient stock was available (no row change).
     */
    public function decrementStock(StoreAddonItem $row, int $quantity = 1): bool
    {
        return $row->decrementStock($quantity);
    }

    /**
     * Increment stock (refund, cancellation, manual restock).
     */
    public function incrementStock(StoreAddonItem $row, int $quantity = 1): bool
    {
        return $row->incrementStock($quantity);
    }

    /**
     * Coerce the caller's attribute array into clean DB-ready values.
     * On create, price is required; on update, unspecified fields are left alone.
     */
    protected function normalizeAttributes(array $attributes, bool $creating): array
    {
        $out = [];

        if (array_key_exists('price', $attributes)) {
            $out['price'] = (float) $attributes['price'];
        } elseif ($creating) {
            // Enforce the NOT NULL constraint up front rather than deferring to the DB.
            throw new \InvalidArgumentException('price is required when creating a store_addon_items row.');
        }

        if (array_key_exists('cost', $attributes)) {
            $out['cost'] = $attributes['cost'] === null || $attributes['cost'] === ''
                ? null
                : (float) $attributes['cost'];
        }

        if (array_key_exists('stock', $attributes)) {
            $out['stock'] = max(0, (int) $attributes['stock']);
        }

        if (array_key_exists('is_available', $attributes)) {
            $out['is_available'] = filter_var($attributes['is_available'], FILTER_VALIDATE_BOOLEAN);
        }

        if (array_key_exists('metadata', $attributes)) {
            $out['metadata'] = $attributes['metadata'];
        }

        return $out;
    }

    /**
     * Guard against cross-seller writes: the store's seller must own the addon item's group.
     * Global/admin groups (seller_id = null) can be stocked by any seller.
     */
    protected function assertSameSeller(Store $store, AddonItem $item): void
    {
        $itemSellerId = $item->group?->seller_id;

        if ($itemSellerId !== null && $itemSellerId !== $store->seller_id) {
            throw new \DomainException('Store and addon item belong to different sellers.');
        }
    }
}
