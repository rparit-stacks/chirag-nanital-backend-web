<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @method static where(string $col, $value)
 * @method static create(array $data)
 */
class Referral extends Model
{
    protected $fillable = [
        'referrer_id',
        'referred_id',
        'referral_code',
        'status',
        'rewarded_at',
        'completed_at',
        'settings',
    ];

    protected $casts = [
        'referrer_id'  => 'integer',
        'referred_id'  => 'integer',
        'rewarded_at'  => 'datetime',
        'completed_at' => 'datetime',
        'settings'     => 'array',
    ];

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referrer_id');
    }

    public function referred(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_id');
    }

    public function earnings(): HasMany
    {
        return $this->hasMany(ReferralEarning::class);
    }
}
