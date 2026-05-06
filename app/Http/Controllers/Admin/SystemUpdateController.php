<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AdminPermissionEnum;
use App\Enums\SystemUpdateStatusEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\SystemUpdate\StoreSystemUpdateRequest;
use App\Jobs\ApplySystemUpdateJob;
use App\Models\Setting;
use App\Models\SystemUpdate;
use App\Services\SystemUpdater;
use App\Traits\ChecksPermissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class SystemUpdateController extends Controller
{
    use ChecksPermissions;

    public function __construct(private readonly SystemUpdater $updater)
    {
    }

    public function index(): View
    {
        $columns = [
            ['data' => 'id', 'name' => 'id', 'title' => __('labels.id'), 'label' => __('labels.id')],
            ['data' => 'version', 'name' => 'version', 'title' => __('labels.version'), 'label' => __('labels.version')],
            ['data' => 'package_name', 'name' => 'package_name', 'title' => __('labels.package'), 'label' => __('labels.package')],
            ['data' => 'status', 'name' => 'status', 'title' => __('labels.status'), 'label' => __('labels.status')],
            ['data' => 'applied_by', 'name' => 'applied_by', 'title' => __('labels.applied_by'), 'label' => __('labels.applied_by')],
            ['data' => 'applied_at', 'name' => 'applied_at', 'title' => __('labels.applied_at'), 'label' => __('labels.applied_at')],
            ['data' => 'action', 'name' => 'action', 'title' => __('labels.action'), 'label' => __('labels.action'), 'orderable' => false, 'searchable' => false],
        ];
        $canUpdate      = $this->hasPermission(AdminPermissionEnum::SETTING_SYSTEM_EDIT());
        $currentVersion = Setting::getCurrentVersion();

        return view('admin.system-updates.index', compact('columns', 'canUpdate', 'currentVersion'));
    }

    /**
     * Accept a ZIP upload and dispatch the queued update job.
     * Returns JSON so the frontend can immediately start polling the row.
     */
    public function store(StoreSystemUpdateRequest $request): JsonResponse
    {
        if (! $this->hasPermission(AdminPermissionEnum::SETTING_SYSTEM_EDIT())) {
            return response()->json([
                'success' => false,
                'message' => __('labels.no_update_permission'),
            ], 403);
        }

        try {
            $userId = (int) (Auth::id() ?? 0);
            $update = $this->updater->prepareUpload($request->file('package'), $userId);

            ApplySystemUpdateJob::dispatch($update->id);

            return response()->json([
                'success'   => true,
                'message'   => __('labels.update_dispatched'),
                'update_id' => $update->id,
                'data'      => $this->serializeRow($update->fresh()),
            ]);
        } catch (\Throwable $e) {
            Log::error('System update dispatch failed: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage() ?: __('labels.something_went_wrong'),
            ], 422);
        }
    }

    /**
     * Return the most recent update row. Drives "resume polling after page refresh".
     */
    public function latest(): JsonResponse
    {
        if (! $this->hasPermission(AdminPermissionEnum::SETTING_SYSTEM_EDIT())) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $this->updater->reconcileStuckRuns();

        $last = SystemUpdate::orderByDesc('id')->first();
        if (! $last) {
            return response()->json(null);
        }

        return response()->json($this->serializeRow($last));
    }

    /**
     * Return a specific update row. Same self-heal pass as latest().
     */
    public function showLog(Request $request, SystemUpdate $update): JsonResponse
    {
        if (! $this->hasPermission(AdminPermissionEnum::SETTING_SYSTEM_EDIT())) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($update->isStale()) {
            $this->updater->reconcileStuckRuns();
            $update->refresh();
        }

        return response()->json($this->serializeRow($update));
    }

    public function datatable(Request $request): JsonResponse
    {
        $query = SystemUpdate::query()->with('appliedBy');

        $totalRecords    = SystemUpdate::count();
        $filteredRecords = $totalRecords;

        if ($request->has('search') && ! empty($request->search['value'])) {
            $search = $request->search['value'];
            $query->where(function ($q) use ($search) {
                $q->where('version', 'like', "%{$search}%")
                  ->orWhere('package_name', 'like', "%{$search}%")
                  ->orWhere('status', 'like', "%{$search}%");
            });
            $filteredRecords = (clone $query)->count();
        }

        if ($request->has('order')) {
            $orderColumn    = $request->columns[$request->order[0]['column']]['data'] ?? 'id';
            $orderDirection = $request->order[0]['dir'] ?? 'desc';
            $query->orderBy($orderColumn, $orderDirection);
        } else {
            $query->orderByDesc('id');
        }

        if ($request->has('start') && $request->has('length')) {
            $query->skip((int) $request->start)->take((int) $request->length);
        }

        $data = $query->get()->map(function (SystemUpdate $item) {
            $statusValue = $item->status?->value ?? 'pending';
            $statusClass = match ($statusValue) {
                SystemUpdateStatusEnum::APPLIED->value => 'bg-success-lt',
                SystemUpdateStatusEnum::FAILED->value  => 'bg-danger-lt',
                default                                => 'bg-secondary-lt',
            };
            $statusHtml = "<span class='badge {$statusClass}'>" . ucfirst($statusValue) . '</span>';
            $appliedBy  = optional($item->appliedBy)->name ?? __('labels.system');
            $appliedAt  = optional($item->applied_at)?->format('Y-m-d H:i');
            $action     = '<a class="btn btn-sm btn-outline-primary" href="'
                . route('admin.system-updates.log', ['update' => $item->id])
                . '" target="_blank">' . e(__('labels.view_log')) . '</a>';

            return [
                'id'           => $item->id,
                'version'      => $item->version,
                'package_name' => $item->package_name,
                'status'       => $statusHtml,
                'applied_by'   => e($appliedBy),
                'applied_at'   => $appliedAt,
                'action'       => $action,
            ];
        });

        return response()->json([
            'draw'            => (int) $request->draw,
            'recordsTotal'    => $totalRecords,
            'recordsFiltered' => $filteredRecords,
            'data'            => $data,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeRow(SystemUpdate $row): array
    {
        return [
            'id'           => $row->id,
            'version'      => $row->version,
            'status'       => $row->status?->value,
            'step'         => $row->step?->value,
            'progress'     => $row->progress ?? 0,
            'log'          => $row->log,
            'heartbeat_at' => optional($row->heartbeat_at)->toDateTimeString(),
            'applied_at'   => optional($row->applied_at)->toDateTimeString(),
            'is_stale'     => $row->isStale(),
        ];
    }
}
