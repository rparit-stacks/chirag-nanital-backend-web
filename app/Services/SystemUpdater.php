<?php

namespace App\Services;

use App\Enums\SystemUpdateStatusEnum;
use App\Enums\SystemUpdateStepEnum;
use App\Models\Setting;
use App\Models\SystemUpdate;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use ZipArchive;

class SystemUpdater
{
    public const UPDATES_DIR = 'updates';
    public const TEMP_DIR    = 'updates/tmp';
    public const BACKUP_DIR  = 'updates/backups';

    /** Commands that are safe to run as part of an update run. */
    protected array $allowedCommands = [
        'config:clear',
        'config:cache',
        'route:clear',
        'route:cache',
        'view:clear',
        'view:cache',
        'optimize',
        'optimize:clear',
        'storage:link',
        'migrate',
        'db:seed',
    ];

    /* ===================================================================
     * Public API — called from the HTTP request (synchronous, fast).
     * =================================================================== */

    /**
     * Move the uploaded ZIP onto local storage and create a pending row.
     * Returns the row so the controller can dispatch the queue job.
     */
    public function prepareUpload(UploadedFile $zip, int $userId): SystemUpdate
    {
        if (strtolower($zip->getClientOriginalExtension()) !== 'zip') {
            throw new \InvalidArgumentException('Only ZIP files are allowed.');
        }

        $this->ensureDirs();
        $this->guardConcurrentRun();

        $storedPath = $zip->storeAs(
            self::UPDATES_DIR,
            pathinfo($zip->getClientOriginalName(), PATHINFO_FILENAME)
            . '_' . bin2hex(random_bytes(6)) . '.zip'
        );

        $checksum = @hash_file('sha256', Storage::path($storedPath)) ?: null;

        return SystemUpdate::create([
            'version'      => 'unknown',
            'package_name' => basename($storedPath),
            'checksum'     => $checksum,
            'status'       => SystemUpdateStatusEnum::PENDING->value,
            'step'         => SystemUpdateStepEnum::QUEUED->value,
            'progress'     => 0,
            'heartbeat_at' => now(),
            'applied_by'   => $userId ?: null,
        ]);
    }

    /* ===================================================================
     * Queued side — called from ApplySystemUpdateJob.
     * =================================================================== */

    /**
     * Execute a prepared update row. Only called from the queue worker.
     */
    public function run(int $updateId): void
    {
        set_time_limit(0);
        ini_set('memory_limit', '512M');

        $update = SystemUpdate::findOrFail($updateId);

        // Another worker may have picked it up, or the watchdog already failed it.
        if ($update->status !== SystemUpdateStatusEnum::PENDING) {
            Log::warning('[SystemUpdater] run() invoked on non-pending row', [
                'id' => $updateId, 'status' => $update->status?->value,
            ]);
            return;
        }

        $log = $update->log ? explode("\n", $update->log) : [];

        $appendLog = function (string $msg) use (&$log, $update) {
            $line = '[' . now()->toDateTimeString() . '] ' . $msg;
            $log[] = $line;
            $update->update([
                'log'          => implode("\n", $log),
                'heartbeat_at' => now(),
            ]);
            Log::info('[SystemUpdater] ' . $msg);
        };

        $setStep = function (SystemUpdateStepEnum $step, int $progress) use ($update, $appendLog) {
            $update->update([
                'step'         => $step->value,
                'progress'     => $progress,
                'heartbeat_at' => now(),
            ]);
            $appendLog('Step: ' . $step->value . ' (' . $progress . '%)');
        };

        $storedPath = self::UPDATES_DIR . '/' . $update->package_name;
        $tempDir    = Storage::path(self::TEMP_DIR . '/' . $update->id);
        $backupDir  = Storage::path(self::BACKUP_DIR . '/' . $update->id);

        try {
            /* -------- Extract -------- */
            $setStep(SystemUpdateStepEnum::EXTRACTING, 5);
            File::ensureDirectoryExists($tempDir);

            if (! Storage::exists($storedPath)) {
                throw new FileNotFoundException('Uploaded package is missing on disk: ' . $storedPath);
            }

            $zipper = new ZipArchive();
            if ($zipper->open(Storage::path($storedPath)) !== true) {
                throw new \RuntimeException('Failed to open ZIP archive.');
            }
            $zipper->extractTo($tempDir);
            $zipper->close();
            $appendLog('Package extracted to temp dir.');

            /* -------- Verify manifest -------- */
            $setStep(SystemUpdateStepEnum::VERIFYING, 15);
            $manifestPath = $tempDir . '/update.json';
            if (! File::exists($manifestPath)) {
                throw new FileNotFoundException('update.json missing from package root.');
            }

            $manifest = json_decode(File::get($manifestPath), true, 512, JSON_THROW_ON_ERROR);
            $this->validateManifest($manifest);
            $vendorUpdate = (bool) ($manifest['vendor_update'] ?? false);

            if ($vendorUpdate) {
                $this->validateVendorUpdate($tempDir, $appendLog);
            }

            $update->update([
                'version'               => $manifest['version'],
                'min_supported_version' => $manifest['min_app_version'] ?? null,
                'notes'                 => $manifest['notes'] ?? '',
                'heartbeat_at'          => now(),
            ]);

            /* -------- Clear caches on OLD code before swap -------- */
            $setStep(SystemUpdateStepEnum::CACHING, 25);
            $this->runSafeArtisan('optimize:clear', $appendLog);

            /* -------- Apply file actions -------- */
            $setStep(SystemUpdateStepEnum::APPLYING, 35);
            File::ensureDirectoryExists($backupDir);
            $projectRoot = base_path();

            foreach ($manifest['actions'] as $action) {
                $this->applyAction($action, $tempDir, $backupDir, $projectRoot, $appendLog);
                $update->update(['heartbeat_at' => now()]);
            }

            /* -------- Migrations (subprocess, sees new code) -------- */
            if (! empty($manifest['run_migrations'])) {
                $setStep(SystemUpdateStepEnum::MIGRATING, 60);
                $this->handleMigrations($tempDir, $appendLog);
            }

            /* -------- Seeders (subprocess) -------- */
            if (! empty($manifest['run_seeders'])) {
                $setStep(SystemUpdateStepEnum::SEEDING, 75);
                $this->handleSeeders($appendLog);
            }

            /* -------- Vendor update (optional) -------- */
            if ($vendorUpdate) {
                $setStep(SystemUpdateStepEnum::VENDOR, 85);
                $this->handleVendorUpdate($tempDir, $appendLog);
            }

            /* -------- Rebuild caches on NEW code -------- */
            $setStep(SystemUpdateStepEnum::CACHING, 92);
            foreach ($manifest['commands'] ?? [] as $cmd) {
                $this->runSafeArtisan($cmd, $appendLog);
            }

            /* -------- Finalize -------- */
            $setStep(SystemUpdateStepEnum::FINALIZING, 98);
            $update->update([
                'status'       => SystemUpdateStatusEnum::APPLIED->value,
                'progress'     => 100,
                'applied_at'   => now(),
                'heartbeat_at' => now(),
            ]);

            $appendLog('✅ Update applied successfully.');
        } catch (\Throwable $e) {
            Log::error('[SystemUpdater] Update failed', [
                'id'    => $update->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $appendLog('❌ FAILED: ' . $e->getMessage());

            try {
                $appendLog('Attempting to restore backup...');
                $this->restoreBackup($backupDir, base_path());
                $this->runSafeArtisan('optimize:clear', $appendLog);
            } catch (\Throwable $restoreError) {
                $appendLog('⚠️  Restore step errored: ' . $restoreError->getMessage());
            }

            $update->update([
                'status'       => SystemUpdateStatusEnum::FAILED->value,
                'applied_at'   => now(),
                'heartbeat_at' => now(),
            ]);

            throw $e;
        } finally {
            // Always clear the temp dir. Keep the backup dir so the admin can inspect.
            File::deleteDirectory($tempDir);
        }
    }

    /* ===================================================================
     * Implementation details.
     * =================================================================== */

    /**
     * Self-heal stale pending rows so a new run can be dispatched even if the
     * previous worker died mid-run without writing a terminal status.
     */
    public function reconcileStuckRuns(): int
    {
        $healed = 0;
        SystemUpdate::where('status', SystemUpdateStatusEnum::PENDING->value)
            ->get()
            ->each(function (SystemUpdate $row) use (&$healed) {
                if (! $row->isStale()) {
                    return;
                }
                $row->update([
                    'status'       => SystemUpdateStatusEnum::FAILED->value,
                    'applied_at'   => now(),
                    'heartbeat_at' => now(),
                    'log'          => trim(($row->log ?? '')
                        . "\n[" . now()->toDateTimeString() . '] ❌ FAILED: stuck — no heartbeat for '
                        . SystemUpdate::STUCK_THRESHOLD_MINUTES . '+ minutes.'),
                ]);
                $healed++;
            });
        return $healed;
    }

    protected function guardConcurrentRun(): void
    {
        $pending = SystemUpdate::where('status', SystemUpdateStatusEnum::PENDING->value)->get();
        foreach ($pending as $row) {
            if (! $row->isStale()) {
                throw new \RuntimeException('Another update is already in progress (run #' . $row->id . ').');
            }
        }
        // Any pending rows left are stale; let reconcileStuckRuns clear them first.
        if ($pending->isNotEmpty()) {
            $this->reconcileStuckRuns();
        }
    }

    protected function applyAction(array $action, string $tempDir, string $backupDir, string $root, callable $log): void
    {
        $type = $action['type']              ?? null;
        $rawSource = (string) ($action['source'] ?? '');
        $rawTarget = (string) ($action['target'] ?? '');

        // Validate paths on both sides — traversal outside the package is a hard no.
        foreach (['source' => $rawSource, 'target' => $rawTarget] as $label => $path) {
            if ($path === '' || str_contains($path, '..') || str_starts_with($path, '/')) {
                throw new \RuntimeException("Invalid {$label} path in update.json: '{$path}'");
            }
        }

        $firstSegment = explode('/', ltrim($rawTarget, '/'))[0];
        $allowedRoots = ['app', 'routes', 'config', 'resources', 'public', 'database', 'packages', 'bootstrap', 'tests'];
        if (! in_array($firstSegment, $allowedRoots, true)) {
            throw new \RuntimeException("Target '{$rawTarget}' is outside allowed roots.");
        }

        $source = $tempDir . '/' . ltrim($rawSource, '/');
        $target = $root . '/' . ltrim($rawTarget, '/');

        if ($type === 'delete') {
            $protectedFiles = ['.env'];
            $protectedRoots = ['vendor', 'storage'];
            if (in_array($rawTarget, $protectedFiles, true) || in_array($firstSegment, $protectedRoots, true)) {
                throw new \RuntimeException("Deletion of '{$rawTarget}' is not allowed.");
            }
        }

        if (in_array($type, ['replace', 'delete'], true) && File::exists($target)) {
            $backup = $backupDir . '/' . ltrim($rawTarget, '/');
            File::ensureDirectoryExists(dirname($backup));
            File::copyDirectory(dirname($target), dirname($backup));
        }

        if ($type === 'delete') {
            if (File::isDirectory($target)) {
                File::deleteDirectory($target);
                $log("Deleted directory {$target}");
            } else {
                File::delete($target);
                $log("Deleted file {$target}");
            }
            return;
        }

        File::ensureDirectoryExists(dirname($target));
        File::isDirectory($source)
            ? File::copyDirectory($source, $target)
            : File::copy($source, $target);

        $log(ucfirst((string) $type) . " {$target}");
    }

    protected function handleMigrations(string $tempDir, callable $log): void
    {
        $dir = $tempDir . '/database/migrations';
        if (! File::isDirectory($dir)) {
            $log('No migrations directory in package; skipping.');
            return;
        }
        File::copyDirectory($dir, database_path('migrations'));
        $this->runSafeArtisan('migrate --force', $log);
        $log('Migrations executed.');
    }

    protected function handleSeeders(callable $log): void
    {
        $this->runSafeArtisan('db:seed --force', $log);
        $log('Seeders executed.');
    }

    protected function handleVendorUpdate(string $tempDir, callable $log): void
    {
        $log('Applying vendor update...');

        File::copy($tempDir . '/composer.json', base_path('composer.json'));
        File::copy($tempDir . '/composer.lock', base_path('composer.lock'));

        $vendorZipPath = base_path('vendor.zip');
        if (File::exists($vendorZipPath)) {
            File::delete($vendorZipPath);
        }
        File::copy($tempDir . '/vendor.zip', $vendorZipPath);

        $log('composer.json, composer.lock and vendor.zip updated.');
    }

    protected function validateVendorUpdate(string $tempDir, callable $log): void
    {
        foreach (['composer.json', 'composer.lock', 'vendor.zip'] as $file) {
            if (! File::exists($tempDir . '/' . $file)) {
                throw new \RuntimeException("Vendor update enabled but required file is missing: {$file}");
            }
        }
        $log('Vendor update validated.');
    }

    protected function restoreBackup(string $backupDir, string $root): void
    {
        if (File::isDirectory($backupDir)) {
            File::copyDirectory($backupDir, $root);
        }
    }

    protected function validateManifest(array $m): void
    {
        if (empty($m['version']) || empty($m['actions'])) {
            throw new \InvalidArgumentException('Invalid update.json: version and actions are required.');
        }

        $current    = Setting::getCurrentVersion();
        $newVersion = (string) $m['version'];
        $minApp     = isset($m['min_app_version']) ? (string) $m['min_app_version'] : null;

        if ($minApp !== null && version_compare($current, $minApp, '<')) {
            throw new \RuntimeException("This update requires app version {$minApp} or higher. Current version is {$current}.");
        }

        if (version_compare($newVersion, $current, '<=')) {
            throw new \RuntimeException("Update version {$newVersion} must be greater than current version {$current}.");
        }
    }

    protected function ensureDirs(): void
    {
        foreach ([self::UPDATES_DIR, self::TEMP_DIR, self::BACKUP_DIR] as $dir) {
            File::ensureDirectoryExists(Storage::path($dir));
        }
    }

    /**
     * Run an allow-listed artisan command as a fresh PHP subprocess. Using a
     * subprocess matters because it boots the *freshly copied* code, not the
     * stale container that handled the original HTTP request.
     */
    protected function runSafeArtisan(string $command, callable $log): void
    {
        $parts   = preg_split('/\s+/', trim($command));
        $binName = $parts[0] ?? '';

        if (! in_array($binName, $this->allowedCommands, true)) {
            $log("Skipped non-allow-listed artisan command: {$command}");
            return;
        }

        $log("Running artisan: {$command}");

        $process = new Process([
            $this->getPhpCliBinary(),
            base_path('artisan'),
            ...$parts,
        ]);

        $process->setTimeout(600);
        $process->run();

        if (! $process->isSuccessful()) {
            $err = trim($process->getErrorOutput() ?: $process->getOutput());
            if ($err === '') {
                $err = 'Command failed with exit code: ' . $process->getExitCode();
            }
            $log("ERROR running `{$command}`: {$err}");
            throw new \RuntimeException("artisan `{$command}` failed: {$err}");
        }

        $out = trim($process->getOutput());
        if ($out !== '') {
            $log($out);
        }
        $log("Completed artisan: {$command}");
    }

    /**
     * Resolve a PHP CLI binary that can actually execute artisan.
     * Order: PHP_CLI_BINARY env override → Symfony finder → common paths → PHP_BINARY.
     */
    protected function getPhpCliBinary(): string
    {
        $override = env('PHP_CLI_BINARY');
        if ($override && is_executable($override)) {
            return $override;
        }

        $found = (new PhpExecutableFinder())->find(false);
        if ($found && is_executable($found) && ! str_contains($found, 'php-fpm')) {
            return $found;
        }

        $version = (string) PHP_MAJOR_VERSION . '.' . (string) PHP_MINOR_VERSION;
        $candidates = [
            "/usr/bin/php{$version}",
            "/usr/local/bin/php{$version}",
            '/usr/bin/php',
            '/usr/local/bin/php',
        ];
        foreach ($candidates as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }

        // Absolute last resort: PHP_BINARY (may be php-fpm on some hosts, will then fail loudly).
        return PHP_BINARY;
    }
}
