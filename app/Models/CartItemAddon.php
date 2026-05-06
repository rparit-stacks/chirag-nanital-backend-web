<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Snapshot of an addon attached to a cart line.
 *
 * Mirrors `OrderItemAddon` shape-for-shape so that checkout can convert cart
 * lines into order items without an impedance mismatch. Like its order-side
 * cousin, it carries no quantity column — a single row means "one of this
 * addon attached to the parent cart item". If the parent line is removed or
 * the cart item is deleted, these rows cascade via the foreign key.
 */
class CartItemAddon extends Model
{
    use SoftDeletes;

    protected $table = 'cart_item_addons';

    protected $fillable = [
        'uuid',
        'cart_item_id',
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
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * Parent cart line this addon is attached to.
     */
    public function cartItem(): BelongsTo
    {
        return $this->belongsTo(CartItem::class);
    }

    /**
     * The addon group this selection belongs to (e.g. "Toppings").
     */
    public function addonGroup(): BelongsTo
    {
        return $this->belongsTo(AddonGroup::class, 'addon_group_id');
    }

    /**
     * The catalog addon item the user selected (e.g. "Extra cheese").
     */
    public function addonItem(): BelongsTo
    {
        return $this->belongsTo(AddonItem::class, 'addon_item_id');
    }
}
