<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeliveryBoyReferral extends Model
{
    protected $fillable = [
        'referrer_id',
        'referred_id',
        'referral_code',
        'status',
        'rewarded_at',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'rewarded_at' => 'datetime',
            'settings'    => 'array',
        ];
    }

    // ─── Relationships ────────────────────────────────────────────────────────

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(DeliveryBoy::class, 'referrer_id');
    }

    public function referred(): BelongsTo
    {
        return $this->belongsTo(DeliveryBoy::class, 'referred_id');
    }

    public function earnings(): HasMany
    {
        return $this->hasMany(DeliveryBoyReferralEarning::class, 'referral_id');
    }

    // ─── Scopes ───────────────────────────────────────────────────────────────

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeRewarded($query)
    {
        return $query->where('status', 'rewarded');
    }
}
