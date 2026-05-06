<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CartItem extends Model
{
    protected $fillable = [
        'cart_id', 'product_id', 'product_variant_id', 'store_id', 'quantity', 'addon_signature', 'save_for_later'
    ];

    protected $casts = [
        'save_for_later' => 'boolean',
    ];
    /**
     * Set the save_for_later attribute to handle ENUM conversion
     */
    public function setSaveForLaterAttribute($value)
    {
        $this->attributes['save_for_later'] = $value ? '1' : '0';
    }

    /**
     * Get the save_for_later attribute as boolean
     */
    public function getSaveForLaterAttribute($value)
    {
        return $value === '1';
    }


    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * Addon selections snapshotted on this cart line.
     *
     * One row per attached addon item — there is no per-addon quantity; the
     * parent's `quantity` multiplier applies to each attached addon at
     * checkout, matching the `order_item_addons` contract.
     */
    public function addons(): HasMany
    {
        return $this->hasMany(CartItemAddon::class, 'cart_item_id');
    }

    /**
     * Deterministic signature over a set of addon_item_ids.
     *
     * SHA1 of the sorted, de-duplicated ids joined with `-`. Two cart add
     * requests that pick the same addon items — regardless of the order the
     * client submitted them in — produce the same signature, which lets us
     * merge quantities instead of creating a second line. Empty input
     * (no addons) maps to null so those lines can keep sharing one row.
     *
     * @param array<int,int|string> $addonItemIds
     */
    public static function buildAddonSignature(array $addonItemIds): ?string
    {
        $ids = array_values(array_unique(array_map('intval', array_filter($addonItemIds, fn ($v) => $v !== null && $v !== ''))));

        if ($ids === []) {
            return null;
        }

        sort($ids, SORT_NUMERIC);

        return sha1(implode('-', $ids));
    }
}
