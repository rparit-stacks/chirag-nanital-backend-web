<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @method static where(string $col, $value)
 * @method static create(array $data)
 */
class ReferralEarning extends Model
{
    protected $fillable = [
        'referral_id',
        'beneficiary_id',
        'beneficiary_type',
        'order_id',
        'order_amount',
        'bonus_method',
        'bonus_value',
        'max_cap',
        'earned_amount',
        'eligible_at',
        'settled_at',
        'wallet_transaction_id',
        'status',
        'reversed_at',
    ];

    protected $casts = [
        'referral_id'          => 'integer',
        'beneficiary_id'       => 'integer',
        'order_id'             => 'integer',
        'wallet_transaction_id'=> 'integer',
        'order_amount'         => 'decimal:2',
        'bonus_value'          => 'decimal:2',
        'max_cap'              => 'decimal:2',
        'earned_amount'        => 'decimal:2',
        'eligible_at'          => 'datetime',
        'settled_at'           => 'datetime',
        'reversed_at'          => 'datetime',
    ];

    public function referral(): BelongsTo
    {
        return $this->belongsTo(Referral::class);
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(User::class, 'beneficiary_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function walletTransaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class);
    }
}
