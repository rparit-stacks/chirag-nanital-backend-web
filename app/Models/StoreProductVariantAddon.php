<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Mapping row: "this addon item is offered on this variant at this store".
 *
 * Pricing, cost, stock, and availability for an addon item are tracked at the
 * store level in `store_addon_items` — regardless of how many variants offer
 * the item. This model carries no pricing/inventory data of its own.
 */
class StoreProductVariantAddon extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'uuid',
        'store_id',
        'product_variant_id',
        'addon_group_id',
        'addon_item_id',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string)Str::uuid();
            }
        });
    }

    /**
     * Get the store that owns this addon.
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * Get the product variant that owns this addon.
     */
    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    /**
     * Get the addon group that owns this variant addon.
     */
    public function addonGroup(): BelongsTo
    {
        return $this->belongsTo(AddonGroup::class, 'addon_group_id');
    }

    /**
     * Get the addon item that owns this variant addon.
     */
    public function addonItem(): BelongsTo
    {
        return $this->belongsTo(AddonItem::class, 'addon_item_id');
    }

    /**
     * The store-level pricing/stock row for this (store, addon_item) pair.
     *
     * This is NOT a Laravel relation: the match requires both `store_id` AND
     * `addon_item_id`, and `belongsTo` cannot model a compound key without
     * silently returning the wrong row under eager-loading. Callers that need
     * bulk access should batch-fetch by (store_id, addon_item_id) pairs — see
     * `ProductVariantResource::lookupStoreAddonItems()` for the canonical
     * pattern. Use this helper only on a single hydrated row.
     */
    public function storeAddonItem(): ?StoreAddonItem
    {
        if (empty($this->store_id) || empty($this->addon_item_id)) {
            return null;
        }

        return StoreAddonItem::query()
            ->where('store_id', $this->store_id)
            ->where('addon_item_id', $this->addon_item_id)
            ->first();
    }

    /**
     * Count of distinct stores that share this (variant, group) attachment.
     *
     * Fast path: if the aggregated listing query already computed the value via
     * selectRaw('COUNT(DISTINCT store_id) as stores_count'), return it straight
     * from the hydrated attributes. Fallback: compute on demand for single-model
     * scenarios (e.g. show / edit) where no aggregate was selected.
     */
    public function getStoresCountAttribute(): int
    {
        if (array_key_exists('stores_count', $this->attributes)) {
            return (int) $this->attributes['stores_count'];
        }

        if (empty($this->product_variant_id) || empty($this->addon_group_id)) {
            return 0;
        }

        return (int) static::query()
            ->where('product_variant_id', $this->product_variant_id)
            ->where('addon_group_id', $this->addon_group_id)
            ->distinct()
            ->count('store_id');
    }

    /**
     * Count of distinct addon items attached for this (variant, group) pair.
     *
     * Fast path / fallback mirrors getStoresCountAttribute().
     */
    public function getItemsCountAttribute(): int
    {
        if (array_key_exists('items_count', $this->attributes)) {
            return (int) $this->attributes['items_count'];
        }

        if (empty($this->product_variant_id) || empty($this->addon_group_id)) {
            return 0;
        }

        return (int) static::query()
            ->where('product_variant_id', $this->product_variant_id)
            ->where('addon_group_id', $this->addon_group_id)
            ->distinct()
            ->count('addon_item_id');
    }

    /**
     * Get mapping row with all relationships loaded.
     */
    public function getFullDetails()
    {
        return $this->load([
            'store',
            'productVariant.product',
            'addonGroup',
            'addonItem',
        ]);
    }
}
