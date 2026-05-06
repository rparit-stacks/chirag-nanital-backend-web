<?php

namespace App\Models;

use App\Enums\SystemUpdateStatusEnum;
use App\Enums\SystemUpdateStepEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SystemUpdate extends Model
{
    use HasFactory;

    /**
     * After how many minutes without a heartbeat a pending row is considered stuck.
     */
    public const STUCK_THRESHOLD_MINUTES = 5;

    protected $fillable = [
        'version',
        'package_name',
        'checksum',
        'min_supported_version',
        'status',
        'step',
        'progress',
        'heartbeat_at',
        'applied_by',
        'applied_at',
        'notes',
        'log',
    ];

    protected $casts = [
        'status'       => SystemUpdateStatusEnum::class,
        'step'         => SystemUpdateStepEnum::class,
        'progress'     => 'integer',
        'applied_at'   => 'datetime',
        'heartbeat_at' => 'datetime',
    ];

    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }

    public function scopeActive($query)
    {
        return $query->where('status', SystemUpdateStatusEnum::APPLIED())->latest();
    }

    /**
     * A run has reached a terminal state (applied or failed).
     */
    public function isTerminal(): bool
    {
        return in_array($this->status, [
            SystemUpdateStatusEnum::APPLIED,
            SystemUpdateStatusEnum::FAILED,
        ], true);
    }

    /**
     * Row is still pending but the worker hasn't heartbeated in a while.
     * Protects the UI from hanging when the PHP process is killed mid-run.
     */
    public function isStale(): bool
    {
        if ($this->status !== SystemUpdateStatusEnum::PENDING) {
            return false;
        }
        $reference = $this->heartbeat_at ?? $this->updated_at ?? $this->created_at;
        if (! $reference) {
            return false;
        }
        return $reference->diffInMinutes(now()) >= self::STUCK_THRESHOLD_MINUTES;
    }
}
