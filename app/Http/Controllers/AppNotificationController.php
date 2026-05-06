<?php

namespace App\Http\Controllers;

use App\Enums\AdminPermissionEnum;
use App\Enums\Notification\NotificationAudienceTypeEnum;
use App\Enums\Notification\NotificationTargetTypeEnum;
use App\Http\Requests\StoreNotificationRequest;
use App\Http\Resources\AppNotificationResource;
use App\Models\AppNotification;
use App\Models\Brand;
use App\Models\Category;
use App\Models\DeliveryBoy;
use App\Models\DeliveryZone;
use App\Models\FeaturedSection;
use App\Models\Product;
use App\Models\Seller;
use App\Models\Store;
use App\Models\User;
use App\Services\AppNotificationService;
use App\Traits\ChecksPermissions;
use App\Types\Api\ApiResponseType;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AppNotificationController extends Controller
{
    use AuthorizesRequests, ChecksPermissions;

    protected bool $viewPermission = false;

    protected bool $createPermission = false;

    public function __construct(protected AppNotificationService $appNotificationService)
    {
        $this->viewPermission = $this->hasPermission(AdminPermissionEnum::NOTIFICATION_VIEW());
        $this->createPermission = $this->hasPermission(AdminPermissionEnum::NOTIFICATION_CREATE());
    }

    public function index(): View
    {
        abort_unless($this->viewPermission, 403, trans('labels.permission_denied'));

        $columns = [
            ['data' => 'id', 'name' => 'id', 'title' => __('labels.id')],
            ['data' => 'details', 'name' => 'details', 'title' => __('labels.details'), 'orderable' => false, 'searchable' => false],
            ['data' => 'message', 'name' => 'message', 'title' => __('labels.message'), 'orderable' => false, 'searchable' => false],
        ];

        $deliveryZones = DeliveryZone::query()->orderBy('name')->get(['id', 'name']);
        $featuredSections = FeaturedSection::query()->orderBy('title')->get(['id', 'title']);
        $audienceTypes = NotificationAudienceTypeEnum::cases();
        $targetTypes = NotificationTargetTypeEnum::cases();
        $createPermission = $this->createPermission;

        return view('admin.app_notifications.index', compact(
            'columns',
            'deliveryZones',
            'featuredSections',
            'audienceTypes',
            'targetTypes',
            'createPermission'
        ));
    }

    public function store(StoreNotificationRequest $request): JsonResponse
    {
        try {
            abort_unless($this->createPermission, 403, trans('labels.permission_denied'));

            $validated = $request->validated();

            if (!empty($validated['target_type'])) {
                $target = $this->validateTargetId($validated['target_type'] ?? null, $validated['target_id'] ?? null);
                $validated['metadata'] = $this->buildMetadata(targetType: $validated['target_type'], target: $target);
            }
            $notification = $this->appNotificationService->createAndDispatch($validated, auth()->id());

            return ApiResponseType::sendJsonResponse(
                success: true,
                message: __('labels.notification_created_successfully'),
                data: [
                    'notification' => new AppNotificationResource($notification),
                    'redirect_url' => route('admin.app-notifications.index'),
                ],
            );
        } catch (ValidationException $e) {
            return ApiResponseType::sendJsonResponse(
                success: false,
                message: __('labels.validation_failed') . ' ' . $e->getMessage(),
                data: $e->errors(),
                status: 422
            );
        } catch (AuthorizationException) {
            return ApiResponseType::sendJsonResponse(
                success: false,
                message: __('labels.permission_denied'),
                status: 403
            );
        } catch (\Throwable $e) {
            return ApiResponseType::sendJsonResponse(
                success: false,
                message: __('labels.failed_to_create_notification'),
                data: ['error' => $e->getMessage()]
            );
        }
    }

    public function datatable(Request $request): JsonResponse
    {
        abort_unless($this->viewPermission, 403, trans('labels.permission_denied'));

        $draw = (int)$request->get('draw', 1);
        $start = (int)$request->get('start', 0);
        $length = (int)$request->get('length', 10);
        $searchValue = $request->get('search')['value'] ?? '';

        $orderColumnIndex = (int)($request->get('order')[0]['column'] ?? 0);
        $orderDirection = $request->get('order')[0]['dir'] === 'asc' ? 'asc' : 'desc';

        $columns = [
            0 => 'id',
            1 => 'title',
            2 => 'audience_type',
            3 => 'target_type',
            4 => 'created_at',
            5 => 'created_by',
            6 => 'created_at',
        ];
        $orderColumn = $columns[$orderColumnIndex] ?? 'created_at';

        $query = AppNotification::query()
            ->with(['creator', 'userMaps', 'zoneMaps'])
            ->withCount(['userMaps', 'zoneMaps']);

        $totalRecords = AppNotification::count();

        if (!empty($searchValue)) {
            $query->where(function ($q) use ($searchValue) {
                $q->where('title', 'like', "%{$searchValue}%")
                    ->orWhere('message', 'like', "%{$searchValue}%")
                    ->orWhere('audience_type', 'like', "%{$searchValue}%")
                    ->orWhere('target_type', 'like', "%{$searchValue}%");
            });
        }

        $filteredRecords = $query->count();

        $data = $query
            ->orderBy($orderColumn, $orderDirection)
            ->skip($start)
            ->take($length)
            ->get()
            ->map(function (AppNotification $notification) {
                $scope = [];
                $scope[] = $notification->userMaps->isEmpty()
                    ? __('labels.all_users')
                    : $notification->userMaps->count() . ' ' . __('labels.selected_users');
                $scope[] = $notification->zoneMaps->isEmpty()
                    ? __('labels.all_zones')
                    : $notification->zoneMaps->count() . ' ' . __('labels.selected_zones');

                $imageHtml = !empty($notification->notification_image) ? view('partials.image', [
                    'image' => $notification->notification_image,
                ])->render() : '';

                return [
                    'id' => $notification->id,
                    'details' => "<div class='d-flex justify-content-start align-items-center'><div class='pe-2'>" .
                        $imageHtml .
                        "</div><div>
                        <p class='m-0 fw-medium text-primary'>" . __('labels.title') . ": {$notification->title}</p>
                        <p class='m-0'>" . __('labels.audience_type') . ': ' . ucfirst($notification->audience_type->value ?? '') . "</p>
                        <p class='m-0'>" . __('labels.target_type') . ': ' . ucfirst(str_replace('_', ' ', $notification->target_type->value ?? '')) . "</p>
                        <p class='m-0'>" . __('labels.scope') . ': ' . implode(' / ', $scope) . "</p>
                        <p class='m-0'>" . __('labels.created_by') . ': ' . $notification->creator?->name . "</p>
                        <p class='m-0'>" . __('labels.created_at') . ': ' . $notification->created_at?->format('Y-m-d') . '</p>'
                        . '</div></div>',
                    'message' => $notification->message,
                ];
            })
            ->toArray();

        return response()->json([
            'draw' => $draw,
            'recordsTotal' => $totalRecords,
            'recordsFiltered' => $filteredRecords,
            'data' => $data,
        ]);
    }

    public function show(int $id): JsonResponse
    {
        try {
            abort_unless($this->viewPermission, 403, trans('labels.permission_denied'));

            $notification = AppNotification::with(['creator', 'userMaps', 'zoneMaps'])->findOrFail($id);

            return ApiResponseType::sendJsonResponse(
                success: true,
                message: __('labels.notification_retrieved_successfully'),
                data: new AppNotificationResource($notification)
            );
        } catch (ModelNotFoundException) {
            return ApiResponseType::sendJsonResponse(
                success: false,
                message: __('labels.notification_not_found'),
                data: [],
                status: 404
            );
        }
    }

    public function searchRecipients(Request $request): JsonResponse
    {
        abort_unless($this->viewPermission, 403, trans('labels.permission_denied'));

        $request->validate([
            'audience_type' => ['required', new Enum(NotificationAudienceTypeEnum::class)],
            'search' => 'nullable|string',
        ]);

        $query = (string)$request->input('search', '');
        $audienceType = NotificationAudienceTypeEnum::from($request->input('audience_type'));

        $results = match ($audienceType) {
            NotificationAudienceTypeEnum::CUSTOMER => User::query()
                ->where(function ($q) {
                    $q->whereNull('access_panel')->orWhere('access_panel', 'web');
                })
                // Keep riders/sellers out of the customer picker — they share the users table.
                ->whereNotExists(function ($q) {
                    $q->select(DB::raw(1))
                        ->from('delivery_boys')
                        ->whereColumn('delivery_boys.user_id', 'users.id')
                        ->whereNull('delivery_boys.deleted_at');
                })
                ->whereNotExists(function ($q) {
                    $q->select(DB::raw(1))
                        ->from('sellers')
                        ->whereColumn('sellers.user_id', 'users.id')
                        ->whereNull('sellers.deleted_at');
                })
                ->where(function ($q) use ($query) {
                    $q->where('name', 'like', "%{$query}%")
                        ->orWhere('email', 'like', "%{$query}%")
                        ->orWhere('mobile', 'like', "%{$query}%");
                })
                ->limit(20)
                ->get()
                ->map(fn(User $user) => [
                    'id' => $user->id,
                    'value' => $user->id,
                    'text' => $user->name . ' - ' . ($user->email ?? $user->mobile ?? ('#' . $user->id)),
                ]),
            NotificationAudienceTypeEnum::SELLER => Seller::query()
                ->whereHas('user', function ($q) use ($query) {
                    $q->where('name', 'like', "%{$query}%")
                        ->orWhere('email', 'like', "%{$query}%")
                        ->orWhere('mobile', 'like', "%{$query}%");
                })
                ->with('user')
                ->limit(20)
                ->get()
                // Return users.id so the value round-trips through app_notification_user_map.user_id.
                ->map(fn(Seller $seller) => [
                    'id' => $seller->user?->id,
                    'value' => $seller->user?->id,
                    'text' => ($seller->user->name ?? 'Seller #' . $seller->id) . ' - ' . ($seller->user->email ?? ''),
                ])
                ->filter(fn($row) => !empty($row['id']))
                ->values(),
            NotificationAudienceTypeEnum::RIDER => DeliveryBoy::query()
                ->where(function ($q) use ($query) {
                    $q->where('full_name', 'like', "%{$query}%")
                        ->orWhereHas('user', function ($userQuery) use ($query) {
                            $userQuery->where('name', 'like', "%{$query}%")
                                ->orWhere('email', 'like', "%{$query}%")
                                ->orWhere('mobile', 'like', "%{$query}%");
                        });
                })
                ->with('user')
                ->limit(20)
                ->get()
                // Return users.id so the value round-trips through app_notification_user_map.user_id.
                ->map(fn(DeliveryBoy $deliveryBoy) => [
                    'id' => $deliveryBoy->user?->id,
                    'value' => $deliveryBoy->user?->id,
                    'text' => ($deliveryBoy->full_name ?: ($deliveryBoy->user->name ?? 'Rider #' . $deliveryBoy->id)) . ' - ' . ($deliveryBoy->user->email ?? ''),
                ])
                ->filter(fn($row) => !empty($row['id']))
                ->values(),
        };

        return response()->json($results->values());
    }

    public function searchTargets(Request $request): JsonResponse
    {
        abort_unless($this->viewPermission, 403, trans('labels.permission_denied'));

        $request->validate([
            'target_type' => ['required', new Enum(NotificationTargetTypeEnum::class)],
            'search' => 'nullable|string',
        ]);

        $query = (string)$request->input('search', '');
        $targetType = NotificationTargetTypeEnum::from($request->input('target_type'));

        $results = match ($targetType) {
            NotificationTargetTypeEnum::PRODUCT => Product::query()
                ->select('id', 'title')
                ->where('title', 'like', "%{$query}%")
                ->limit(20)
                ->get()
                ->map(fn($item) => ['id' => $item->id, 'value' => $item->id, 'text' => $item->title]),
            NotificationTargetTypeEnum::FEATURED_SECTION => FeaturedSection::query()
                ->select('id', 'title')
                ->where('title', 'like', "%{$query}%")
                ->limit(20)
                ->get()
                ->map(fn($item) => ['id' => $item->id, 'value' => $item->id, 'text' => $item->title]),
            NotificationTargetTypeEnum::BRAND => Brand::query()
                ->select('id', 'title')
                ->where('title', 'like', "%{$query}%")
                ->limit(20)
                ->get()
                ->map(fn($item) => ['id' => $item->id, 'value' => $item->id, 'text' => $item->title]),
            NotificationTargetTypeEnum::CATEGORY => Category::query()
                ->select('id', 'title')
                ->where('title', 'like', "%{$query}%")
                ->limit(20)
                ->get()
                ->map(fn($item) => ['id' => $item->id, 'value' => $item->id, 'text' => $item->title]),
            NotificationTargetTypeEnum::STORE => Store::query()
                ->select('id', 'name')
                ->where('name', 'like', "%{$query}%")
                ->limit(20)
                ->get()
                ->map(fn($item) => ['id' => $item->id, 'value' => $item->id, 'text' => $item->name]),
        };

        return response()->json($results->values());
    }

    protected function buildMetadata(string $targetType, $target): ?array
    {
        if (empty($targetType)) {
            return null;
        }

        return match ($targetType) {
            NotificationTargetTypeEnum::PRODUCT(), NotificationTargetTypeEnum::FEATURED_SECTION(), NotificationTargetTypeEnum::BRAND(), NotificationTargetTypeEnum::CATEGORY() => [
                'id' => $target->id,
                'title' => $target->title,
                'slug' => $target->slug,
                'image' => $target->image,
            ],
            NotificationTargetTypeEnum::STORE() => [
                'id' => $target->id,
                'title' => $target->name,
                'slug' => $target->slug,
                'image' => $target->image,
            ],
            default => [
                'target_type' => $targetType,
            ]
        };
    }

    /**
     * @throws ValidationException
     */
    protected function validateTargetId(null|string|NotificationTargetTypeEnum $targetType, ?int $targetId): ?object
    {
        if (empty($targetType) || empty($targetId)) {
            return null;
        }

        $resolvedTargetType = $targetType instanceof NotificationTargetTypeEnum
            ? $targetType
            : NotificationTargetTypeEnum::from($targetType);

        $target = match ($resolvedTargetType) {
            NotificationTargetTypeEnum::PRODUCT => Product::where('id', $targetId)->get()->first(),
            NotificationTargetTypeEnum::FEATURED_SECTION => FeaturedSection::where('id', $targetId)->get()->first(),
            NotificationTargetTypeEnum::BRAND => Brand::where('id', $targetId)->get()->first(),
            NotificationTargetTypeEnum::CATEGORY => Category::where('id', $targetId)->get()->first(),
            NotificationTargetTypeEnum::STORE => Store::where('id', $targetId)->get()->first(),
        };

        if (empty($target)) {
            throw ValidationException::withMessages([
                'target_id' => ['The selected target is invalid for the selected target type.'],
            ]);
        }
        $target->image = match ($resolvedTargetType) {
            NotificationTargetTypeEnum::PRODUCT => $target->main_image,
            NotificationTargetTypeEnum::BRAND => $target->logo,
            NotificationTargetTypeEnum::CATEGORY => $target->image,
            NotificationTargetTypeEnum::STORE => $target->store_logo,
            default => '',
        };
        return $target;
    }
}
