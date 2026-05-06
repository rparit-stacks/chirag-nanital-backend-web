<?php

namespace App\Http\Resources\Product;

use App\Models\StoreAddonItem;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

class ProductVariantResource extends JsonResource
{
    public function toArray($request): array
    {
        // Format attributes as key-value pairs
        $attributes = [];

        // Try to get the variant attributes directly from the variant
        if ($this->relationLoaded('attributes')) {
            foreach ($this->attributes as $attribute) {
                if ($attribute->attribute && $attribute->attributeValue) {
                    $attributeSlug = $attribute->attribute->slug;
                    $attributeValue = $attribute->attributeValue->title;
                    $attributes[$attributeSlug] = $attributeValue;
                }
            }
        } else {
            // If attributes aren't loaded, load them now
            $this->load(['attributes.attribute', 'attributes.attributeValue']);

            foreach ($this->attributes as $attribute) {
                if ($attribute->attribute && $attribute->attributeValue) {
                    $attributeSlug = $attribute->attribute->slug;
                    $attributeValue = $attribute->attributeValue->title;
                    $attributes[$attributeSlug] = $attributeValue;
                }
            }
        }

        $cartItem = $this->isInUserCart();
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'image' => $this->image ?? '',
            'weight' => (float)$this->weight ?? 0,
            'height' => (float)$this->height ?? 0,
            'breadth' => (float)$this->breadth ?? 0,
            'length' => (float)$this->length ?? 0,
            'availability' => $this->availability,
            'cart_item' => $cartItem,
            'barcode' => $this->barcode,
            'is_default' => $this->is_default,
            'price' => $this->storeProductVariants->first()->price ?? null,
            'special_price' => $this->storeProductVariants->first()->special_price ?? null,
            'store_id' => $this->storeProductVariants->first()->store_id ?? null,
            'store_slug' => $this->storeProductVariants->first()->store->slug ?? null,
            'store_name' => $this->storeProductVariants->first()->store->name ?? null,
            'stock' => $this->storeProductVariants->first()->stock ?? null,
            'sku' => $this->storeProductVariants->first()->sku ?? null,
            'attributes' => $attributes,
            'addon_groups' => $this->formatAddonGroups(),
        ];
    }

    /**
     * Group the eager-loaded storeVariantAddons rows into addon groups with their items.
     */
    protected function formatAddonGroups(): array
    {
        if (! $this->relationLoaded('storeVariantAddons') || $this->storeVariantAddons->isEmpty()) {
            return [];
        }
        // Scope the attachments to the same store we rendered price/stock for.
        $storeId = $this->storeProductVariants->first()->store_id ?? null;
        if (empty($storeId)) {
            return [];
        }

        $rows = $this->storeVariantAddons
            ->where('store_id', $storeId)
            ->filter(fn ($row) => $row->addonGroup && $row->addonItem);

        if ($rows->isEmpty()) {
            return [];
        }

        $inventory = $this->lookupStoreAddonItems(
            (int) $storeId,
            $rows->pluck('addon_item_id')->filter()->unique()->values()->all()
        );

        return $rows
            ->filter(function ($row) use ($inventory) {
                $inv = $inventory->get((int) $row->addon_item_id);

                // When a per-store inventory row exists, it is authoritative for availability.
                if ($inv) {
                    return (bool) $inv->is_available;
                }

                $item = $row->addonItem;

                return ($item->is_available ?? true);
            })
            ->groupBy('addon_group_id')
            ->map(function ($groupRows) use ($inventory) {
                $group = $groupRows->first()->addonGroup;

                return [
                    'id'             => $group->id,
                    'uuid'           => $group->uuid,
                    'title'          => $group->title,
                    'slug'           => $group->slug,
                    'selection_type' => $group->selection_type?->value ?? (is_string($group->selection_type) ? $group->selection_type : null),
                    'is_required'    => (bool) $group->is_required,
                    'sort_order'     => (int) $group->sort_order,
                    'items' => $groupRows
                        ->sortBy(fn ($row) => (int) ($row->addonItem->sort_order ?? 0))
                        ->values()
                        ->map(function ($row) use ($inventory) {
                            $item = $row->addonItem;
                            $inv  = $inventory->get((int) $row->addon_item_id);

                            $price = $inv && $inv->price !== null
                                ? (float) $inv->price
                                : (float) ($item->price ?? 0);
                            $cost = $inv && $inv->cost !== null
                                ? (float) $inv->cost
                                : ($item->cost !== null ? (float) $item->cost : null);

                            return [
                                'id'           => $item->id,
                                'uuid'         => $item->uuid,
                                'title'        => $item->title,
                                'slug'         => $item->slug,
                                'indicator'    => $item->indicator?->value ?? (is_string($item->indicator) ? $item->indicator : null),
                                'price'        => $price,
                                'stock'        => $inv ? (int) $inv->stock : 0,
                                'is_available' => $inv
                                    ? (bool) $inv->is_available
                                    : ($item->is_available ?? true),
                            ];
                        })
                        ->toArray(),
                ];
            })
            ->sortBy('sort_order')
            ->values()
            ->toArray();
    }

    /**
     * Batch-fetch StoreAddonItem rows for the (store, addon_item) pairs we need, cached per request.
     *
     * Avoids an N+1 when a variant exposes many addon items, and keeps `StoreProductVariantAddon`
     * free of a compound-key relation that Laravel's `belongsTo` cannot model safely.
     *
     * @param array<int,int> $addonItemIds
     * @return Collection<int, StoreAddonItem>  Keyed by addon_item_id.
     */
    protected function lookupStoreAddonItems(int $storeId, array $addonItemIds): Collection
    {
        static $cache = [];

        if (empty($addonItemIds)) {
            return collect();
        }

        $missing = [];
        foreach ($addonItemIds as $id) {
            $key = $storeId.':'.$id;
            if (! array_key_exists($key, $cache)) {
                $missing[] = (int) $id;
            }
        }

        if (! empty($missing)) {
            StoreAddonItem::query()
                ->where('store_id', $storeId)
                ->whereIn('addon_item_id', $missing)
                ->get()
                ->each(function (StoreAddonItem $row) use (&$cache, $storeId) {
                    $cache[$storeId.':'.(int) $row->addon_item_id] = $row;
                });

            // Mark misses so we don't re-query them on subsequent variants.
            foreach ($missing as $id) {
                $key = $storeId.':'.$id;
                if (! array_key_exists($key, $cache)) {
                    $cache[$key] = null;
                }
            }
        }

        $result = collect();
        foreach ($addonItemIds as $id) {
            $row = $cache[$storeId.':'.(int) $id] ?? null;
            if ($row) {
                $result->put((int) $id, $row);
            }
        }

        return $result;
    }
}
