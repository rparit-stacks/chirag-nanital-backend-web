<?php

namespace App\Services;

use App\Enums\Addon\AddonGroupStatusEnum;
use App\Models\AddonGroup;
use App\Models\AddonItem;
use App\Models\Seller;
use App\Models\StoreAddonItem;
use App\Models\StoreProductVariantAddon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AddonGroupService
{
    /**
     * Build the base seller-scoped query for addon groups.
     */
    public function querySellerGroups(Seller $seller): Builder
    {
        return AddonGroup::query()
            ->where('seller_id', $seller->id)
            ->with('items');
    }

    /**
     * Create a new addon group together with its items in a single transaction.
     */
    public function createWithItems(Seller $seller, array $data): AddonGroup
    {

        return DB::transaction(function () use ($seller, $data) {
            $group = AddonGroup::create([
                'seller_id'      => $seller->id,
                'title'          => $data['title'],
                'selection_type' => $data['selection_type'],
                'is_required'    => (bool) ($data['is_required'] ?? false),
                'sort_order'     => (int) ($data['sort_order'] ?? 0),
                'status'         => $data['status'] ?? AddonGroupStatusEnum::ACTIVE(),
            ]);
            $this->syncItems($group, collect($data['items'] ?? []));

            return $group->load('items');
        });
    }

    /**
     * Update the addon group and reconcile its items.
     */
    public function updateWithItems(AddonGroup $group, array $data): AddonGroup
    {
        return DB::transaction(function () use ($group, $data) {
            $group->update([
                'title'          => $data['title'],
                'selection_type' => $data['selection_type'],
                'is_required'    => (bool) ($data['is_required'] ?? false),
                'sort_order'     => (int) ($data['sort_order'] ?? $group->sort_order),
                'status'         => $data['status'],
            ]);

            $this->syncItems($group, collect($data['items'] ?? []));

            return $group->load('items');
        });
    }

    /**
     * Soft delete the addon group and all of its items.
     *
     * Because AddonGroup/AddonItem use SoftDeletes, the onDelete('cascade')
     * foreign keys on store_product_variant_addons and store_addon_items never
     * fire — child rows would survive as orphans and crash downstream screens
     * that try to resolve the now-missing parent. We must explicitly cascade
     * the soft delete here (and to every dependent store-level row).
     */
    public function deleteGroup(AddonGroup $group): void
    {
        DB::transaction(function () use ($group) {
            $itemIds = $group->items()->pluck('id')->all();

            // Drop product-variant attachments for this group across every store.
            StoreProductVariantAddon::query()
                ->where('addon_group_id', $group->id)
                ->delete();

            // Drop store-level pricing/inventory rows for every item of the group.
            if (! empty($itemIds)) {
                StoreAddonItem::query()
                    ->whereIn('addon_item_id', $itemIds)
                    ->delete();
            }

            $group->items()->delete();
            $group->delete();
        });
    }

    /**
     * Add new items, update existing ones, and soft delete items removed from the form.
     */
    protected function syncItems(AddonGroup $group, Collection $items): void
    {
        $keptIds = [];

        foreach ($items->values() as $index => $item) {
            $payload = [
                'addon_group_id' => $group->id,
                'title'          => $item['title'],
                'price'          => (float) ($item['price'] ?? 0),
                'cost'           => isset($item['cost']) && $item['cost'] !== '' ? (float) $item['cost'] : null,
                'indicator'      => $item['indicator'] ?? null,
                'is_available'   => filter_var($item['is_available'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'sort_order'     => (int) ($item['sort_order'] ?? $index),
                'status'         => $item['status'] ?? AddonGroupStatusEnum::ACTIVE->value,
            ];

            if (! empty($item['id'])) {
                $existing = $group->items()->where('id', $item['id'])->first();
                if ($existing) {
                    $existing->update($payload);
                    $keptIds[] = $existing->id;
                    continue;
                }
            }

            $created = AddonItem::create($payload);
            $keptIds[] = $created->id;
        }

        // Remove items that are no longer in the submitted list, and cascade
        // the soft-delete to any store-level attachment / inventory rows that
        // still reference them (the FK cascades on the DB side only fire on a
        // hard delete, which we don't do here).
        $removedItems = $group->items()
            ->whereNotIn('id', $keptIds ?: [0])
            ->get();

        if ($removedItems->isNotEmpty()) {
            $removedIds = $removedItems->pluck('id')->all();

            StoreProductVariantAddon::query()
                ->where('addon_group_id', $group->id)
                ->whereIn('addon_item_id', $removedIds)
                ->delete();

            StoreAddonItem::query()
                ->whereIn('addon_item_id', $removedIds)
                ->delete();

            $removedItems->each->delete();
        }
    }
}
