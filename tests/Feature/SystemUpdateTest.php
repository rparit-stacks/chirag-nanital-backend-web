<?php

use App\Enums\SystemUpdateStatusEnum;
use App\Enums\SystemUpdateStepEnum;
use App\Jobs\ApplySystemUpdateJob;
use App\Models\SystemUpdate;
use App\Services\SystemUpdater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * Feature tests for the system updater's robustness layer.
 *
 * These tests focus on the three behaviours the rewrite is meant to guarantee:
 * 1. The upload path creates a pending row and dispatches the queue job without running the work inline.
 * 2. The stale-heartbeat watchdog flips stuck pending rows to failed so the UI can unstick.
 * 3. The isStale()/isTerminal() helpers used by the poll endpoint behave correctly.
 */
beforeEach(function () {
    Storage::fake('local');
    Queue::fake();
});

it('prepareUpload creates a pending row and stores the ZIP', function () {
    $zip = UploadedFile::fake()->create('update.zip', 10, 'application/zip');

    $update = app(SystemUpdater::class)->prepareUpload($zip, userId: 0);

    expect($update->status)->toBe(SystemUpdateStatusEnum::PENDING);
    expect($update->step)->toBe(SystemUpdateStepEnum::QUEUED);
    expect($update->progress)->toBe(0);
    expect($update->heartbeat_at)->not->toBeNull();
    expect($update->package_name)->toEndWith('.zip');
    Storage::assertExists(SystemUpdater::UPDATES_DIR . '/' . $update->package_name);
});

it('rejects non-zip uploads', function () {
    $file = UploadedFile::fake()->create('update.tar', 1, 'application/x-tar');

    expect(fn () => app(SystemUpdater::class)->prepareUpload($file, 0))
        ->toThrow(InvalidArgumentException::class);
});

it('refuses a second upload while another run is still active', function () {
    SystemUpdate::create([
        'version'      => '1.0.0',
        'package_name' => 'existing.zip',
        'status'       => SystemUpdateStatusEnum::PENDING->value,
        'step'         => SystemUpdateStepEnum::APPLYING->value,
        'heartbeat_at' => now(),
    ]);

    $zip = UploadedFile::fake()->create('update.zip', 10, 'application/zip');

    expect(fn () => app(SystemUpdater::class)->prepareUpload($zip, 0))
        ->toThrow(RuntimeException::class);
});

it('allows a new upload when the pending row is stale (heartbeat > threshold)', function () {
    SystemUpdate::create([
        'version'      => '1.0.0',
        'package_name' => 'stale.zip',
        'status'       => SystemUpdateStatusEnum::PENDING->value,
        'step'         => SystemUpdateStepEnum::APPLYING->value,
        'heartbeat_at' => now()->subMinutes(SystemUpdate::STUCK_THRESHOLD_MINUTES + 1),
    ]);

    $zip = UploadedFile::fake()->create('update.zip', 10, 'application/zip');

    $newRow = app(SystemUpdater::class)->prepareUpload($zip, 0);

    expect($newRow->status)->toBe(SystemUpdateStatusEnum::PENDING);
    // The stale row should have been flipped by the guard.
    expect(SystemUpdate::where('package_name', 'stale.zip')->first()->status)
        ->toBe(SystemUpdateStatusEnum::FAILED);
});

it('reconcileStuckRuns flips rows with no heartbeat past the threshold', function () {
    $fresh = SystemUpdate::create([
        'version'      => '1.1.0',
        'package_name' => 'fresh.zip',
        'status'       => SystemUpdateStatusEnum::PENDING->value,
        'heartbeat_at' => now(),
    ]);
    $stuck = SystemUpdate::create([
        'version'      => '1.2.0',
        'package_name' => 'stuck.zip',
        'status'       => SystemUpdateStatusEnum::PENDING->value,
        'heartbeat_at' => now()->subMinutes(SystemUpdate::STUCK_THRESHOLD_MINUTES + 5),
    ]);

    $healed = app(SystemUpdater::class)->reconcileStuckRuns();

    expect($healed)->toBe(1);
    expect($fresh->fresh()->status)->toBe(SystemUpdateStatusEnum::PENDING);
    expect($stuck->fresh()->status)->toBe(SystemUpdateStatusEnum::FAILED);
    expect($stuck->fresh()->log)->toContain('stuck — no heartbeat');
});

it('isStale returns false for terminal rows regardless of heartbeat', function () {
    $applied = SystemUpdate::create([
        'version'      => '1.0.0',
        'package_name' => 'applied.zip',
        'status'       => SystemUpdateStatusEnum::APPLIED->value,
        'heartbeat_at' => now()->subDay(),
    ]);

    expect($applied->isStale())->toBeFalse();
    expect($applied->isTerminal())->toBeTrue();
});

it('ApplySystemUpdateJob failed() hook finalizes a lingering pending row', function () {
    $row = SystemUpdate::create([
        'version'      => '1.0.0',
        'package_name' => 'job-failed.zip',
        'status'       => SystemUpdateStatusEnum::PENDING->value,
        'heartbeat_at' => now(),
    ]);

    (new ApplySystemUpdateJob($row->id))->failed(new RuntimeException('worker died'));

    $row->refresh();
    expect($row->status)->toBe(SystemUpdateStatusEnum::FAILED);
    expect($row->log)->toContain('worker died');
});
