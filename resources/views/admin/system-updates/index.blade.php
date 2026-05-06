@php use Carbon\Carbon; @endphp
@extends('layouts.admin.app', ['page' => $menuAdmin['system_updates']['active'] ?? ""])


@section('title', __('labels.system_updates'))

@section('header_data')
    @php
        $page_title = __('labels.system_updates');
        $page_pretitle = __('labels.list');
    @endphp
@endsection

@php
    $breadcrumbs = [
        ['title' => __('labels.home'), 'url' => route('admin.dashboard')],
        ['title' => __('labels.system_updates'), 'url' => null],
    ];
@endphp

@section('admin-content')
    <div id="system-update-config"
         data-latest-url="{{ route('admin.system-updates.latest') }}"
         data-log-url="{{ route('admin.system-updates.log', ['update' => '__ID__']) }}"
         data-label-starting="{{ __('labels.starting') }}"
         data-label-processing="{{ __('labels.processing') }}"
         data-label-applied="{{ __('labels.applied') }}"
         data-label-failed="{{ __('labels.failed') }}"
         data-label-applying="{{ __('labels.applying') }}"
         data-label-apply="{{ __('labels.apply_update') }}"
         data-label-copied="{{ __('labels.copied') }}"
         data-label-copy="{{ __('labels.copy') }}"
         data-label-stuck="{{ __('labels.update_stuck_warning') }}"
         data-label-step-queued="{{ __('labels.step_queued') }}"
         data-label-step-extracting="{{ __('labels.step_extracting') }}"
         data-label-step-verifying="{{ __('labels.step_verifying') }}"
         data-label-step-applying="{{ __('labels.step_applying') }}"
         data-label-step-migrating="{{ __('labels.step_migrating') }}"
         data-label-step-seeding="{{ __('labels.step_seeding') }}"
         data-label-step-vendor="{{ __('labels.step_vendor') }}"
         data-label-step-caching="{{ __('labels.step_caching') }}"
         data-label-step-finalizing="{{ __('labels.step_finalizing') }}"
         data-label-step-rolling-back="{{ __('labels.step_rolling_back') }}"
         hidden></div>

    <div class="">
        @if (session('update_log'))
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>{{ __('labels.latest_update_log') }}
                        @if(session('update_version'))
                            (v{{ session('update_version') }})
                        @endif
                        @if(session('update_id'))
                            #{{ session('update_id') }}
                        @endif
                    </span>
                    <button class="btn btn-dark" type="button"
                            onclick="navigator.clipboard.writeText(document.getElementById('latest-update-log').innerText);this.innerText='{{ __('labels.copied') }}';setTimeout(()=>this.innerText='{{ __('labels.copy') }}',1500);">{{ __('labels.copy') }}</button>
                </div>
                <div class="card-body">
                    <pre id="latest-update-log" class="mb-0"
                         style="white-space: pre-wrap; word-break: break-word; max-height: 300px; overflow: auto;">{{ session('update_log') }}</pre>
                </div>
            </div>
        @endif

        @php
            // Mirror the cron check used on the Notification Settings page.
            // The updater depends on the same queue worker — if cron-log.txt
            // never appears, the queue never runs, and any dispatched update
            // would sit forever on 'pending'. Gate the upload behind it.
            $cronLogPath   = storage_path('logs/cron-log.txt');
            $cronLogExists = file_exists($cronLogPath);
            $cronLogMTime  = $cronLogExists ? @filemtime($cronLogPath) : null;
            $cronIsFresh   = $cronLogMTime && (time() - $cronLogMTime) < 600; // seen in the last 10 min
            $phpBinary = shell_exec('which php')
                ? trim(shell_exec('which php'))
                : '/usr/local/bin/php';
        @endphp

        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h3 class="card-title mb-0">{{ __('labels.system_updates') }}</h3>
                <div class="d-flex align-items-center gap-3">
                    @if($cronLogExists)
                        <span class="badge {{ $cronIsFresh ? 'bg-success-lt' : 'bg-warning-lt' }}">
                            <span
                                class="status-dot {{ $cronIsFresh ? 'status-dot-animated bg-success' : 'bg-warning' }} me-1"></span>
                            {{ $cronIsFresh ? __('labels.queue_worker_running') : __('labels.queue_worker_stale') }}
                        </span>
                    @else
                        <span class="badge bg-danger-lt">
                            <span class="status-dot bg-danger me-1"></span>
                            {{ __('labels.queue_worker_not_set_up') }}
                        </span>
                    @endif
                    <div class="text-muted">{{ __('labels.current_version') }}:
                        <strong>v{{ $currentVersion ?? config('app.version') }}</strong>
                    </div>
                </div>
            </div>
            <div class="card-body">
                @if(! $canUpdate)
                    <div class="text-muted">{{ __('labels.no_update_permission') }}</div>
                @elseif(! $cronLogExists)
                    <div class="alert alert-warning mb-3 flex-column" role="alert">
                        <div class="d-flex align-items-start gap-2">
                            <div>
                                <h4 class="alert-heading mb-1">{{ __('labels.queue_setup_required_title') }}</h4>
                                <p class="mb-2">{{ __('labels.queue_setup_required_body') }}</p>
                            </div>
                        </div>
                        <div class="fw-bold mt-3 mb-1">{{ __('messages.add_cron_instruction') }}</div>
                        <pre class="p-3 rounded bg-gray-700 text-white" style="overflow:auto; font-size:0.9rem;">
* * * * * {{ $phpBinary }} {{ base_path('artisan') }} queue:work --stop-when-empty >> {{ storage_path('logs/cron-log.txt') }} 2>&1
                        </pre>
                        <div class="small text-muted mb-2">{{ __('messages.php_path_note') }}</div>

                        <div class="small">
                            <div>{{ __('messages.cron_not_detected_full') }}</div>
                            <div>{{ __('labels.cron_not_detected') }}. {{ __('messages.log_file_not_found') }}</div>
                            <code>{{ $cronLogPath }}</code>
                            <div>{{ __('messages.cron_has_not_run_yet') }}</div>
                        </div>

                        <div class="mt-3">
                            {{ __('messages.view_documentation') }}:
                            <a href="https://docs-hyper-local.vercel.app/introduction" target="_blank"
                               class="alert-link">
                                {{ __('labels.please_refer_to_docs') }}
                            </a>.
                        </div>
                    </div>

                    <div class="text-muted small">{{ __('labels.upload_unlocks_after_queue') }}</div>
                @else
                    @if(! $cronIsFresh)
                        <div class="alert alert-warning small" role="alert">
                            <strong>{{ __('labels.queue_worker_stale') }}:</strong>
                            {{ __('labels.queue_worker_stale_hint') }}
                            @if($cronLogMTime)
                                <div class="text-muted">
                                    {{ __('labels.last_updated') }}:
                                    {{ Carbon::createFromTimestamp($cronLogMTime)->toDayDateTimeString() }}
                                </div>
                            @endif
                        </div>
                    @endif

                    <div id="update-error-box" class="alert alert-danger d-none">
                        <ul id="update-error-list" class="mb-0"></ul>
                    </div>

                    <form id="update-form" method="POST" action="{{ route('admin.system-updates.store') }}"
                          enctype="multipart/form-data">
                        @csrf
                        <div class="mb-3">
                            <label for="package" class="form-label">{{ __('labels.update_zip_file') }}</label>
                            <input type="file" class="form-control" id="package" name="package" required>
                            <div class="form-text">{{ __('labels.update_zip_help') }}</div>
                        </div>
                        <button id="apply-update-btn" type="submit"
                                class="btn btn-primary">{{ __('labels.apply_update') }}</button>
                    </form>

                    <div id="live-log-card" class="card mt-4 d-none">
                        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <span>{{ __('labels.live_update_log') }}</span>
                                <span id="live-log-version" class="text-muted"></span>
                                <span id="live-log-step" class="text-muted small"></span>
                            </div>
                            <div class="d-flex align-items-center justify-content-center gap-1">
                                <span id="live-log-status"
                                      class="badge bg-secondary-lt">{{ __('labels.starting') }}</span>
                                <button class="btn btn-sm btn-outline-secondary" type="button"
                                        id="copy-live-log">{{ __('labels.copy') }}</button>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="progress mb-3" style="height: 6px;">
                                <div id="live-log-progress-bar" class="progress-bar"
                                     role="progressbar" style="width: 0;"
                                     aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">0%
                                </div>
                            </div>
                            <div id="live-log-stale-banner" class="alert alert-warning d-none py-2 small mb-3">
                                {{ __('labels.update_stuck_warning') }}
                            </div>
                            <pre id="live-log-text" class="mb-0"
                                 style="white-space: pre-wrap; word-break: break-word; max-height: 300px; overflow: auto;"></pre>
                        </div>
                    </div>
                @endif
            </div>
        </div>

        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <h3 class="card-title mb-0">{{ __('labels.update_history') }}</h3>
                    <x-breadcrumb :items="$breadcrumbs"/>
                </div>
                <div class="card-actions">
                    <button class="btn btn-outline-primary" id="refresh">
                        {{ __('labels.refresh') }}
                    </button>
                </div>
            </div>
            <div class="card-table">
                <div class="row w-full p-3">
                    <x-datatable id="system-updates-table" :columns="$columns"
                                 route="{{ route('admin.system-updates.datatable') }}"
                                 :options="['order' => [[0, 'desc']], 'pageLength' => 10]"/>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('assets/js/system-updates.js') }}" defer></script>
@endpush
