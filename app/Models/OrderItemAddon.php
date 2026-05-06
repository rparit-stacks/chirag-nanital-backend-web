<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Snapshot of a selected addon attached to an order item.
 *
 * Mirrors `CartItemAddon` shape-for-shape — one row = "one of this addon
 * attached to the parent order item" (no quantity column). Price is captured
 * at order-creation time from the matching `cart_item_addons` row so that
 * later seller-side price or availability changes don't retroactively rewrite
 * historical orders.
 *
 * Column names (`addon_group_id`, `addon_item_id`) match the migration
 * `2026_04_13_180449_create_order_item_addons_table.php`.
 */
class OrderItemAddon extends Model
{
    use SoftDeletes;

    protected $table = 'order_item_addons';

    protected $fillable = [
        'uuid',
        'order_item_id',
        'addon_group_id',
        'addon_item_id',
        'price',
        'metadata',
    ];

    protected $casts = [
        'price'    => 'decimal:2',
        'metadata' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * Get the order item that owns this addon.
     */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    /**
     * Get the addon group for this order item addon.
     */
    public function addonGroup(): BelongsTo
    {
        return $this->belongsTo(AddonGroup::class, 'addon_group_id');
    }

    /**
     * Get the addon item for this order item addon.
     */
    public function addonItem(): BelongsTo
    {
        return $this->belongsTo(AddonItem::class, 'addon_item_id');
    }

    /**
     * Get addon with all relationships loaded.
     */
    public function getFullDetails()
    {
        return $this->load([
            'orderItem',
            'addonGroup',
            'addonItem',
        ]);
    }
}
