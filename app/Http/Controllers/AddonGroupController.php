<?php

namespace App\Http\Controllers;

use App\Enums\Addon\AddonGroupSelectionTypeEnum;
use App\Enums\Addon\AddonGroupStatusEnum;
use App\Enums\Addon\AddonItemIndicatorEnum;
use App\Enums\DefaultSystemRolesEnum;
use App\Enums\SellerPermissionEnum;
use App\Exceptions\SellerNotFoundException;
use App\Http\Requests\AddonGroup\StoreUpdateAddonGroupRequest;
use App\Http\Resources\AddonGroupResource;
use App\Models\AddonGroup;
use App\Services\AddonGroupService;
use App\Traits\ChecksPermissions;
use App\Traits\PanelAware;
use App\Types\Api\ApiResponseType;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class AddonGroupController extends Controller
{
    use ChecksPermissions, PanelAware, AuthorizesRequests;

    public float $sellerId;
    protected bool $createPermission = false;
    protected bool $editPermission = false;
    protected bool $deletePermission = false;

    public function __construct(protected AddonGroupService $addonGroupService)
    {
        $user = auth()->user();
        $seller = $user?->seller();
        $this->sellerId = $seller ? $seller->id : 0;
        if ($this->getPanel() === 'seller') {
            $this->createPermission = $this->hasPermission(SellerPermissionEnum::ADDON_GROUP_CREATE()) || $user->hasRole(DefaultSystemRolesEnum::SELLER());
            $this->editPermission = $this->hasPermission(SellerPermissionEnum::ADDON_GROUP_EDIT()) || $user->hasRole(DefaultSystemRolesEnum::SELLER());
            $this->deletePermission = $this->hasPermission(SellerPermissionEnum::ADDON_GROUP_DELETE()) || $user->hasRole(DefaultSystemRolesEnum::SELLER());
        }
    }

    /**
     * Render the addon-group listing screen for the seller panel.
     */
    public function index(): View
    {
        $this->authorize('viewAny', AddonGroup::class);

        $columns = [
            ['data' => 'id', 'name' => 'id', 'title' => __('labels.id')],
            ['data' => 'title', 'name' => 'title', 'title' => __('labels.addon_group_title')],
            ['data' => 'selection_type', 'name' => 'selection_type', 'title' => __('labels.addon_selection_type')],
            ['data' => 'is_required', 'name' => 'is_required', 'title' => __('labels.addon_is_required')],
            ['data' => 'items_count', 'name' => 'items_count', 'title' => __('labels.addon_items_count'), 'orderable' => false, 'searchable' => false],
            ['data' => 'status', 'name' => 'status', 'title' => __('labels.status')],
            ['data' => 'created_at', 'name' => 'created_at', 'title' => __('labels.created_at')],
            ['data' => 'action', 'name' => 'action', 'title' => __('labels.action'), 'orderable' => false, 'searchable' => false],
        ];

        return view($this->panelView('addon_groups.index'), [
            'columns' => $columns,
            'createPermission' => $this->createPermission,
            'editPermission' => $this->editPermission,
            'deletePermission' => $this->deletePermission,
        ]);
    }

    /**
     * Render the create form (single page – group + items together).
     */
    public function create(): View
    {
        $this->authorize('create', AddonGroup::class);

        return view($this->panelView('addon_groups.form'), [
            'group' => null,
            'selectionTypes' => AddonGroupSelectionTypeEnum::cases(),
            'statuses' => AddonGroupStatusEnum::cases(),
            'indicators' => AddonItemIndicatorEnum::cases(),
            'editPermission' => $this->editPermission,
        ]);
    }

    /**
     * Render the edit form populated with the group + its items.
     */
    public function edit(int $id): View
    {
        $group = $this->resolveOwnedGroup($id);
        $this->authorize('update', $group);

        return view($this->panelView('addon_groups.form'), [
            'group' => $group,
            'selectionTypes' => AddonGroupSelectionTypeEnum::cases(),
            'statuses' => AddonGroupStatusEnum::cases(),
            'indicators' => AddonItemIndicatorEnum::cases(),
            'editPermission' => $this->editPermission,
        ]);
    }

    /**
     * Persist a new addon group together with its items.
     */
    public function store(StoreUpdateAddonGroupRequest $request): JsonResponse
    {
        try {
            $this->authorize('create', AddonGroup::class);
            $seller = $this->ensureSeller();
            $group = $this->addonGroupService->createWithItems($seller, $request->validated());

            return ApiResponseType::sendJsonResponse(
                success: true,
                message: 'labels.addon_group_created_successfully',
                data: [
                    'group'        => new AddonGroupResource($group),
                    'redirect_url' => route('seller.addon-groups.index'),
                ],
                status: 201,
            );
        } catch (ValidationException $e) {
            return ApiResponseType::sendJsonResponse(false, 'labels.validation_failed', $e->errors(), 422);
        } catch (AuthorizationException) {
            return ApiResponseType::sendJsonResponse(false, 'labels.permission_denied', [], 403);
        } catch (SellerNotFoundException) {
            return ApiResponseType::sendJsonResponse(false, 'labels.seller_not_found', [], 404);
        } catch (Throwable $e) {
            Log::error('AddonGroup store failed', ['error' => $e->getMessage()]);
            return ApiResponseType::sendJsonResponse(false, 'labels.something_went_wrong', [], 500);
        }
    }

    /**
     * Update an existing addon group + reconcile its items.
     */
    public function update(StoreUpdateAddonGroupRequest $request, int $id): JsonResponse
    {
        try {
            $group = $this->resolveOwnedGroup($id);
            $this->authorize('update', $group);
            $updated = $this->addonGroupService->updateWithItems($group, $request->validated());

            return ApiResponseType::sendJsonResponse(
                success: true,
                message: 'labels.addon_group_updated_successfully',
                data: [
                    'group'        => new AddonGroupResource($updated),
                    'redirect_url' => route('seller.addon-groups.index'),
                ],
            );
        } catch (ValidationException $e) {
            return ApiResponseType::sendJsonResponse(false, 'labels.validation_failed', $e->errors(), 422);
        } catch (AuthorizationException) {
            return ApiResponseType::sendJsonResponse(false, 'labels.permission_denied', [], 403);
        } catch (ModelNotFoundException) {
            return ApiResponseType::sendJsonResponse(false, 'labels.addon_group_not_found', [], 404);
        } catch (Throwable $e) {
            Log::error('AddonGroup update failed', ['error' => $e->getMessage()]);
            return ApiResponseType::sendJsonResponse(false, 'labels.something_went_wrong', [], 500);
        }
    }

    /**
     * Soft delete an addon group and its items.
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $group = $this->resolveOwnedGroup($id);
            $this->authorize('delete', $group);
            $this->addonGroupService->deleteGroup($group);

            return ApiResponseType::sendJsonResponse(true, 'labels.addon_group_deleted_successfully', []);
        } catch (AuthorizationException) {
            return ApiResponseType::sendJsonResponse(false, 'labels.permission_denied', [], 403);
        } catch (ModelNotFoundException) {
            return ApiResponseType::sendJsonResponse(false, 'labels.addon_group_not_found', [], 404);
        } catch (Throwable $e) {
            Log::error('AddonGroup delete failed', ['error' => $e->getMessage()]);
            return ApiResponseType::sendJsonResponse(false, 'labels.something_went_wrong', [], 500);
        }
    }

    /**
     * Datatable feed for the listing screen.
     */
    public function datatable(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AddonGroup::class);
        $seller = $this->ensureSeller();

        $draw = (int)$request->get('draw');
        $start = (int)$request->get('start', 0);
        $length = (int)$request->get('length', 10);
        $searchValue = (string)($request->get('search')['value'] ?? '');
        $orderColIdx = (int)($request->get('order')[0]['column'] ?? 0);
        $orderDir = (string)($request->get('order')[0]['dir'] ?? 'desc');

        $columns = ['id', 'title', 'selection_type', 'is_required', 'items_count', 'status', 'created_at'];
        $orderColumn = $columns[$orderColIdx] ?? 'id';
        if ($orderColumn === 'items_count') {
            $orderColumn = 'id';
        }

        $base = $this->addonGroupService->querySellerGroups($seller)->withCount('items');
        $totalRecords = (clone $base)->count();

        if ($searchValue !== '') {
            $base->where('title', 'like', "%{$searchValue}%");
        }
        $filteredRecords = (clone $base)->count();

        $rows = $base->orderBy($orderColumn, $orderDir)
            ->skip($start)
            ->take($length)
            ->get()
            ->map(function (AddonGroup $group) {
                return [
                    'id' => $group->id,
                    'title' => e($group->title),
                    'selection_type' => view('partials.status', [
                        'status' => $group->selection_type?->value ?? '',
                    ])->render(),
                    'is_required' => $group->is_required
                        ? '<span class="badge bg-orange-lt">' . __('labels.yes') . '</span>'
                        : '<span class="badge bg-secondary-lt">' . __('labels.no') . '</span>',
                    'items_count' => '<span class="badge bg-blue-lt">' . ($group->items_count ?? 0) . '</span>',
                    'status' => view('partials.status', ['status' => $group->status?->value ?? ''])->render(),
                    'created_at' => optional($group->created_at)->format('Y-m-d'),
                    'action' => view('partials.actions', [
                        'modelName' => 'addon-group',
                        'id' => $group->id,
                        'title' => $group->title,
                        'mode' => 'full_view',
                        'route' => route('seller.addon-groups.edit', $group->id),
                        'editPermission' => $this->editPermission,
                        'deletePermission' => $this->deletePermission,
                    ])->render(),
                ];
            })
            ->toArray();

        return response()->json([
            'draw' => $draw,
            'recordsTotal' => $totalRecords,
            'recordsFiltered' => $filteredRecords,
            'data' => $rows,
        ]);
    }

    /**
     * Resolve an addon group that belongs to the authenticated seller.
     */
    protected function resolveOwnedGroup(int $id): AddonGroup
    {
        $seller = $this->ensureSeller();

        return AddonGroup::with('items')
            ->where('seller_id', $seller->id)
            ->where('id', $id)
            ->firstOrFail();
    }
}
