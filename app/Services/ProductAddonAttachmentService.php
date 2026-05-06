<?php

namespace App\Services;

use App\Models\AddonGroup;
use App\Models\ProductVariant;
use App\Models\Seller;
use App\Models\Store;
use App\Models\StoreAddonItem;
use App\Models\StoreProductVariantAddon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Attach / detach addon groups to store product variants.
 *
 * Logical "attachment" = one (product_variant, addon_group) pair applied to
 * zero-or-more stores. In the DB that becomes one row per
 * (store, variant, group, item) in `store_product_variant_addons`. This row
 * is a pure mapping — pricing, cost, and stock for each addon item live in
 * `store_addon_items` (one row per store × addon_item) and are managed via
 * {@see StoreAddonItemService}.
 */
class ProductAddonAttachmentService
{
    /**
     * Build a seller-scoped base query keyed by (variant, group) — one logical attachment per key.
     */
    public function queryForSeller(Seller $seller): Builder
    {
        // Distinct (product_variant_id, addon_group_id) pairs belonging to this seller's stores.
        return StoreProductVariantAddon::query()
            ->whereHas('store', fn ($q) => $q->where('seller_id', $seller->id));
    }

    /**
     * Build the aggregated query of distinct (variant, group) attachments for a seller.
     * Returns a Builder so the caller can chain ->paginate() / ->get() as needed.
     *
     * Titles are resolved through Eloquent relationships (productVariant.product, addonGroup);
     * only the distinct-count aggregates use selectRaw because Eloquent has no native helper
     * for COUNT(DISTINCT ...) on the same table.
     */
    public function listAttachments(Seller $seller, ?string $search = null): Builder
    {
        $spvaTable = (new StoreProductVariantAddon)->getTable();

        return StoreProductVariantAddon::query()
            ->whereHas('store', fn (Builder $q) => $q->where('seller_id', $seller->id))
            ->when($search, function (Builder $query) use ($search) {
                $query->where(function (Builder $w) use ($search) {
                    $w->whereHas('productVariant.product', fn (Builder $q) => $q->where('title', 'like', "%{$search}%"))
                        ->orWhereHas('productVariant', fn (Builder $q) => $q->where('title', 'like', "%{$search}%"))
                        ->orWhereHas('addonGroup', fn (Builder $q) => $q->where('title', 'like', "%{$search}%"));
                });
            })
            ->with([
                'productVariant:id,title,product_id',
                'productVariant.product:id,title',
                'addonGroup:id,title',
            ])
            ->selectRaw(
                'MIN(' . $spvaTable . '.id) as id, '
                . $spvaTable . '.product_variant_id, '
                . $spvaTable . '.addon_group_id, '
                . 'COUNT(DISTINCT ' . $spvaTable . '.store_id) as stores_count, '
                . 'COUNT(DISTINCT ' . $spvaTable . '.addon_item_id) as items_count, '
                . 'MAX(' . $spvaTable . '.updated_at) as updated_at'
            )
            ->groupBy($spvaTable . '.product_variant_id', $spvaTable . '.addon_group_id')
            ->orderByDesc('updated_at');
    }

    /**
     * Return the data needed to render the form for a (variant, group) pair.
     *
     * Price / cost / stock are sourced from the seller's `store_addon_items`
     * rows (store-level inventory) and flattened into the `inventory` map so
     * the form can show read-only context next to each (store × item) checkbox.
     * The authoritative place to edit those values is the "Addon Inventory"
     * screen — not this form.
     *
     * @return array{
     *     variant: ProductVariant,
     *     group: AddonGroup,
     *     stores: Collection<int, Store>,
     *     items: Collection,
     *     existing: Collection<int, array{store_id:int, addon_item_id:int}>,
     *     inventory: Collection<int, array{store_id:int, addon_item_id:int, price:float, cost:float|null, stock:int, is_available:bool}>
     * }
     */
    public function buildFormPayload(Seller $seller, ProductVariant $variant, AddonGroup $group): array
    {
        // Stores that carry this variant AND belong to this seller.
        $stores = Store::query()
            ->where('seller_id', $seller->id)
            ->whereHas('productVariants', fn ($q) => $q->where('product_variant_id', $variant->id))
            ->orderBy('name')
            ->get();

        $items = $group->items()->where('status', 'active')->get();

        $storeIds = $stores->pluck('id');
        $itemIds  = $items->pluck('id');

        $existing = StoreProductVariantAddon::query()
            ->where('product_variant_id', $variant->id)
            ->where('addon_group_id', $group->id)
            ->whereIn('store_id', $storeIds)
            ->get()
            ->map(fn ($row) => [
                'store_id'      => (int) $row->store_id,
                'addon_item_id' => (int) $row->addon_item_id,
            ]);

        $inventory = StoreAddonItem::query()
            ->whereIn('store_id', $storeIds)
            ->whereIn('addon_item_id', $itemIds)
            ->get()
            ->map(fn (StoreAddonItem $row) => [
                'store_id'      => (int) $row->store_id,
                'addon_item_id' => (int) $row->addon_item_id,
                'price'         => (float) $row->price,
                'cost'          => $row->cost !== null ? (float) $row->cost : null,
                'stock'         => (int) $row->stock,
                'is_available'  => (bool) $row->is_available,
            ]);

        return compact('variant', 'group', 'stores', 'items', 'existing', 'inventory');
    }

    /**
     * Upsert mapping rows for a (variant, group) pair.
     *
     * Payload shape:
     *   stores => [
     *     ['store_id' => X, 'apply' => bool, 'items' => [
     *        ['addon_item_id' => Y],
     *     ]],
     *   ]
     *
     * For stores with apply=false, existing mapping rows for that
     * (store, variant, group) are deleted. For apply=true, mapping rows are
     * created/restored for every addon_item_id in the payload and mapping rows
     * for items no longer in the payload are deleted.
     */
    public function saveAttachment(
        Seller $seller,
        ProductVariant $variant,
        AddonGroup $group,
        array $storesPayload,
    ): void {
        DB::transaction(function () use ($seller, $variant, $group, $storesPayload) {
            $sellerStoreIds = Store::query()
                ->where('seller_id', $seller->id)
                ->pluck('id')
                ->all();

            foreach ($storesPayload as $storePayload) {
                $storeId = (int) ($storePayload['store_id'] ?? 0);
                if (! in_array($storeId, $sellerStoreIds, true)) {
                    continue; // ignore stores that don't belong to this seller
                }

                $apply = filter_var($storePayload['apply'] ?? false, FILTER_VALIDATE_BOOLEAN);

                if (! $apply) {
                    StoreProductVariantAddon::query()
                        ->where('store_id', $storeId)
                        ->where('product_variant_id', $variant->id)
                        ->where('addon_group_id', $group->id)
                        ->delete();
                    continue;
                }

                $keptItemIds = [];
                foreach (($storePayload['items'] ?? []) as $itemPayload) {
                    $itemId = (int) ($itemPayload['addon_item_id'] ?? 0);
                    if ($itemId <= 0) {
                        continue;
                    }

                    // The DB has a unique index on (store, variant, group, item) that ignores
                    // deleted_at, so a soft-deleted row would collide on insert. Look the row
                    // up with trashed rows included; restore if present, create otherwise.
                    $row = StoreProductVariantAddon::withTrashed()
                        ->where('store_id', $storeId)
                        ->where('product_variant_id', $variant->id)
                        ->where('addon_group_id', $group->id)
                        ->where('addon_item_id', $itemId)
                        ->first();

                    if ($row) {
                        if ($row->trashed()) {
                            $row->restore();
                        }
                    } else {
                        StoreProductVariantAddon::create([
                            'store_id'           => $storeId,
                            'product_variant_id' => $variant->id,
                            'addon_group_id'     => $group->id,
                            'addon_item_id'      => $itemId,
                        ]);
                    }

                    $keptItemIds[] = $itemId;
                }

                // Remove rows for items that are no longer in the payload (e.g. item deleted from group).
                StoreProductVariantAddon::query()
                    ->where('store_id', $storeId)
                    ->where('product_variant_id', $variant->id)
                    ->where('addon_group_id', $group->id)
                    ->when($keptItemIds, fn ($q) => $q->whereNotIn('addon_item_id', $keptItemIds))
                    ->delete();
            }
        });
    }

    /**
     * Save many (variant × group) attachments in a single transaction.
     * Each attachment entry has the same shape as a single saveAttachment payload.
     *
     * @param array<int, array{product_variant_id:int, addon_group_id:int, stores:array}> $attachments
     *
     * @return array{saved:int, skipped:int} counts for feedback.
     */
    public function saveBulkAttachments(Seller $seller, array $attachments): array
    {
        $saved = 0;
        $skipped = 0;

        DB::transaction(function () use ($seller, $attachments, &$saved, &$skipped) {
            // Pre-load the seller's variants + groups once so we can validate ownership without hammering the DB.
            $variantIds = collect($attachments)->pluck('product_variant_id')->unique()->all();
            $groupIds   = collect($attachments)->pluck('addon_group_id')->unique()->all();

            $variants = ProductVariant::query()
                ->whereIn('id', $variantIds)
                ->whereHas('product', fn ($q) => $q->where('seller_id', $seller->id))
                ->get()
                ->keyBy('id');

            $groups = AddonGroup::query()
                ->whereIn('id', $groupIds)
                ->where('seller_id', $seller->id)
                ->get()
                ->keyBy('id');

            foreach ($attachments as $attachment) {
                $variant = $variants->get((int) $attachment['product_variant_id']);
                $group   = $groups->get((int) $attachment['addon_group_id']);

                if (! $variant || ! $group) {
                    $skipped++;
                    continue;
                }

                $this->saveAttachment($seller, $variant, $group, $attachment['stores'] ?? []);
                $saved++;
            }
        });

        return compact('saved', 'skipped');
    }

    /**
     * Remove every row for a (variant, group) pair across the seller's stores.
     */
    public function detachAttachment(Seller $seller, ProductVariant $variant, AddonGroup $group): void
    {
        $sellerStoreIds = Store::query()
            ->where('seller_id', $seller->id)
            ->pluck('id');

        StoreProductVariantAddon::query()
            ->where('product_variant_id', $variant->id)
            ->where('addon_group_id', $group->id)
            ->whereIn('store_id', $sellerStoreIds)
            ->delete();
    }
}
