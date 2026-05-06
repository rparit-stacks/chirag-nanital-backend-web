<?php

namespace App\Models;

use App\Enums\Addon\AddonGroupStatusEnum;
use App\Enums\Addon\AddonItemIndicatorEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class AddonItem extends Model
{
    use SoftDeletes;

    protected $table = 'addon_items';

    protected $fillable = [
        'uuid',
        'addon_group_id',
        'title',
        'slug',
        'price',
        'cost',
        'indicator',
        'is_available',
        'sort_order',
        'status',
        'metadata',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'cost' => 'decimal:2',
        'is_available' => 'boolean',
        'metadata' => 'array',
        'status' => AddonGroupStatusEnum::class,
        'indicator' => AddonItemIndicatorEnum::class,
    ];

    public function setTitleAttribute($value): void
    {
        $this->attributes['title'] = $value;
        $this->attributes['slug'] = generateUniqueSlug(model: self::class, title: $value, id: $this->id ?? null);
        if (empty($this->id)) {
            $this->attributes['uuid'] = (string)Str::uuid();
        }
    }

    /**
     * Get the addon group that owns this item.
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(AddonGroup::class, 'addon_group_id');
    }

    /**
     * Get all store variant addons for this item.
     */
    public function storeVariantAddons(): HasMany
    {
        return $this->hasMany(StoreProductVariantAddon::class, 'addon_item_id');
    }

    /**
     * Per-store inventory / pricing rows for this catalog addon item.
     */
    public function storeItems(): HasMany
    {
        return $this->hasMany(StoreAddonItem::class, 'addon_item_id');
    }
}
