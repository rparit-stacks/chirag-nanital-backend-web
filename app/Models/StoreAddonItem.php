<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Store-level inventory and pricing for an addon item.
 *
 * One row per (store, addon_item). This is the single source of truth for
 * an addon item's stock, price, and cost within a given store — regardless
 * of how many product variants offer that addon.
 */
class StoreAddonItem extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'store_addon_items';

    protected $fillable = [
        'uuid',
        'store_id',
        'addon_item_id',
        'price',
        'cost',
        'stock',
        'is_available',
        'metadata',
    ];

    protected $casts = [
        'price'        => 'decimal:2',
        'cost'         => 'decimal:2',
        'stock'        => 'integer',
        'is_available' => 'boolean',
        'metadata'     => 'array',
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
     * The store that owns this inventory row.
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * The catalog addon item this row is tracking for the store.
     */
    public function addonItem(): BelongsTo
    {
        return $this->belongsTo(AddonItem::class, 'addon_item_id');
    }

    /**
     * Whether the addon can currently be sold: flagged available AND stock > 0.
     */
    public function isInStock(int $quantity = 1): bool
    {
        return $this->is_available && $this->stock >= $quantity;
    }

    /**
     * Decrement stock atomically. Returns true on success, false if insufficient stock.
     *
     * Uses a conditional UPDATE so two concurrent orders cannot both decrement
     * past zero — mirrors the pattern used for product stock.
     */
    public function decrementStock(int $quantity = 1): bool
    {
        if ($quantity <= 0) {
            return false;
        }

        $affected = static::query()
            ->whereKey($this->getKey())
            ->where('stock', '>=', $quantity)
            ->update(['stock' => DB::raw("stock - {$quantity}")]);

        if ($affected === 0) {
            return false;
        }

        $this->refresh();

        return true;
    }

    /**
     * Increment stock (e.g. on order cancellation / refund).
     */
    public function incrementStock(int $quantity = 1): bool
    {
        if ($quantity <= 0) {
            return false;
        }

        $this->increment('stock', $quantity);

        return true;
    }

    /**
     * Effective price for checkout — always the store-level value.
     */
    public function getEffectivePrice(): float
    {
        return (float) $this->price;
    }

    /**
     * Effective cost for margin calculations — nullable.
     */
    public function getEffectiveCost(): ?float
    {
        return $this->cost !== null ? (float) $this->cost : null;
    }
}
