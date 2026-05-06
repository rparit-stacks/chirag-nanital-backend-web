<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryBoyReferralEarning extends Model
{
    protected $fillable = [
        'referral_id',
        'beneficiary_id',
        'beneficiary_type',
        'bonus_amount',
        'wallet_transaction_id',
        'settled_at',
    ];

    protected function casts(): array
    {
        return [
            'bonus_amount' => 'decimal:2',
            'settled_at'   => 'datetime',
        ];
    }

    // ─── Relationships ────────────────────────────────────────────────────────

    public function referral(): BelongsTo
    {
        return $this->belongsTo(DeliveryBoyReferral::class, 'referral_id');
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(DeliveryBoy::class, 'beneficiary_id');
    }
}
