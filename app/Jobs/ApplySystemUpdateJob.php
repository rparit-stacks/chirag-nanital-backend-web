<?php

namespace App\Jobs;

use App\Enums\SystemUpdateStatusEnum;
use App\Models\SystemUpdate;
use App\Services\SystemUpdater;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ApplySystemUpdateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** 30 minutes — plenty for migrations + cache rebuilds. */
    public int $timeout = 1800;

    /** Never auto-retry an update; the admin should inspect and re-dispatch manually. */
    public int $tries = 1;

    public bool $failOnTimeout = true;

    public function __construct(public int $updateId)
    {
    }

    public function handle(SystemUpdater $updater): void
    {
        $updater->run($this->updateId);
    }

    /**
     * Last line of defence: if the worker died or the job timed out, guarantee
     * the row ends in a terminal state so the UI and future runs unstick.
     */
    public function failed(?\Throwable $e = null): void
    {
        $update = SystemUpdate::find($this->updateId);
        if (! $update || $update->status !== SystemUpdateStatusEnum::PENDING) {
            return;
        }

        $message = $e?->getMessage() ?? 'Queue worker reported job failure';
        Log::error('[SystemUpdater] Job failed hook fired', [
            'id' => $this->updateId, 'error' => $message,
        ]);

        $update->update([
            'status'       => SystemUpdateStatusEnum::FAILED->value,
            'applied_at'   => now(),
            'heartbeat_at' => now(),
            'log'          => trim(($update->log ?? '')
                . "\n[" . now()->toDateTimeString() . '] ❌ FAILED: ' . $message),
        ]);
    }
}
