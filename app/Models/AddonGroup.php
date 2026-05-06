<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use App\Enums\Addon\AddonGroupStatusEnum;
use App\Enums\Addon\AddonGroupSelectionTypeEnum;

class AddonGroup extends Model
{
    use SoftDeletes;

    protected $table = 'addon_groups';

    protected $fillable = [
        'uuid',
        'seller_id',
        'title',
        'slug',
        'selection_type',
        'is_required',
        'sort_order',
        'status',
        'metadata',
    ];

    protected $casts = [
        'is_required'    => 'boolean',
        'metadata'       => 'array',
        'status'         => AddonGroupStatusEnum::class,
        'selection_type' => AddonGroupSelectionTypeEnum::class,
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
     * Get the seller that owns this addon group (null = global/admin-owned).
     */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    /**
     * Scope: groups owned by a given seller plus global (seller_id NULL) groups.
     */
    public function scopeForSeller(Builder $query, int $sellerId): Builder
    {
        return $query->where(function (Builder $q) use ($sellerId) {
            $q->where('seller_id', $sellerId)->orWhereNull('seller_id');
        });
    }

    /**
     * Get all addon items for this group.
     */
    public function items(): HasMany
    {
        return $this->hasMany(AddonItem::class, 'addon_group_id')
            ->orderBy('sort_order');
    }

    /**
     * Get active addon items for this group.
     */
    public function activeItems(): HasMany
    {
        return $this->items()
            ->where('status', AddonGroupStatusEnum::ACTIVE->value)
            ->where('is_available', true);
    }
}
