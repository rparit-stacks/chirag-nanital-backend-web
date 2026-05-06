<?php

namespace App\Services;

use App\Enums\Notification\NotificationAudienceTypeEnum;
use App\Enums\SpatieMediaCollectionName;
use App\Jobs\SendAppNotificationJob;
use App\Models\AppNotification;
use App\Models\DeliveryBoy;
use App\Models\Seller;
use App\Models\User;
use App\Notifications\AdminCustomNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AppNotificationService
{
    /**
     * @throws \Throwable
     * @throws ValidationException
     */
    public function createAndDispatch(array $data, ?int $createdBy = null): AppNotification
    {
        $audienceType = $data['audience_type'] instanceof NotificationAudienceTypeEnum
            ? $data['audience_type']
            : NotificationAudienceTypeEnum::from($data['audience_type']);

        $targetType = $audienceType->value === NotificationAudienceTypeEnum::CUSTOMER()
            ? ($data['target_type'] ?? null)
            : null;
        $userIds = $this->normalizeIds($data['user_ids'] ?? []);
        $zoneIds = $this->normalizeIds($data['zone_ids'] ?? []);

        $this->validateSelectedUserIds($audienceType, $userIds);

        DB::beginTransaction();

        try {
            $appNotification = AppNotification::create([
                'audience_type' => $audienceType,
                'title' => $data['title'],
                'message' => $data['message'],
                'target_type' => $targetType,
                'metadata' => $data['metadata'] ?? null,
                'created_by' => $createdBy,
            ]);

            if (! empty($userIds)) {
                $timestamp = now();

                $appNotification->userMaps()->insert(
                    collect($userIds)
                        ->map(fn (int $userId) => [
                            'notification_id' => $appNotification->id,
                            'user_id' => $userId,
                            'user_type' => $audienceType->value,
                            'created_at' => $timestamp,
                            'updated_at' => $timestamp,
                        ])->all()
                );
            }

            if (! empty($zoneIds)) {
                $timestamp = now();

                $appNotification->zoneMaps()->insert(
                    collect($zoneIds)
                        ->map(fn (int $zoneId) => [
                            'notification_id' => $appNotification->id,
                            'zone_id' => $zoneId,
                            'created_at' => $timestamp,
                            'updated_at' => $timestamp,
                        ])->all()
                );
            }

            if (! empty($data['image'])) {
                $appNotification->addMediaFromRequest('image')
                    ->toMediaCollection(SpatieMediaCollectionName::APP_NOTIFICATION_IMAGE());
            }

            DB::commit();

            SendAppNotificationJob::dispatch($appNotification->id);

            return $appNotification->load(['userMaps', 'zoneMaps']);
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function send(AppNotification $appNotification, int $chunkSize = 200): void
    {
        $this->recipientUserIdsQuery($appNotification)
            ->chunk($chunkSize, function ($rows) use ($appNotification) {
                $recipientIds = $rows->pluck('id')->filter()->map(fn ($id) => (int) $id)->values();

                if ($recipientIds->isEmpty()) {
                    return;
                }

                User::whereIn('id', $recipientIds)
                    ->get()
                    ->each(function (User $user) use ($appNotification) {
                        $user->notify(new AdminCustomNotification($appNotification));
                    });
            });
    }

    protected function recipientUserIdsQuery(AppNotification $appNotification): Builder
    {
        $selectedUserIds = $appNotification->userMaps->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        $selectedZoneIds = $appNotification->zoneMaps->pluck('zone_id')->map(fn ($id) => (int) $id)->all();

        return match ($appNotification->audience_type->value) {
            NotificationAudienceTypeEnum::CUSTOMER() => $this->customerRecipientQuery($selectedUserIds, $selectedZoneIds),
            NotificationAudienceTypeEnum::SELLER() => $this->sellerRecipientQuery($selectedUserIds, $selectedZoneIds),
            NotificationAudienceTypeEnum::RIDER() => $this->riderRecipientQuery($selectedUserIds, $selectedZoneIds),
            default => throw new \InvalidArgumentException('Invalid audience type'),
        };
    }

    protected function customerRecipientQuery(array $selectedUserIds, array $selectedZoneIds): Builder
    {
        $query = User::query()
            ->select('users.id')
            ->where(function ($q) {
                $q->whereNull('users.access_panel')
                    ->orWhere('users.access_panel', 'web');
            })
            // Exclude rider/seller users — both are stored in `users` with a NULL or 'web' access_panel
            // and would otherwise match the customer cohort.
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
            ->orderBy('users.id');

        if (! empty($selectedUserIds)) {
            $query->whereIn('users.id', $selectedUserIds);
        }

        if (! empty($selectedZoneIds)) {
            // Filter customers by their associated delivery zones via user_zone pivot
            $query->join('user_zone', 'user_zone.user_id', '=', 'users.id')
                ->whereIn('user_zone.zone_id', $selectedZoneIds);
        }

        return $query->distinct();
    }

    protected function sellerRecipientQuery(array $selectedUserIds, array $selectedZoneIds): Builder
    {
        $query = User::query()
            ->select('users.id')
            ->join('sellers', 'sellers.user_id', '=', 'users.id')
            ->whereNull('sellers.deleted_at')
            ->orderBy('users.id');

        // userMaps stores users.id (the admin picker returns users.id), so filter on that.
        if (! empty($selectedUserIds)) {
            $query->whereIn('users.id', $selectedUserIds);
        }

        if (! empty($selectedZoneIds)) {
            $query->join('stores', 'stores.seller_id', '=', 'sellers.id')
                ->join('store_zone', 'store_zone.store_id', '=', 'stores.id')
                ->whereNull('stores.deleted_at')
                ->whereIn('store_zone.zone_id', $selectedZoneIds);
        }

        return $query->distinct();
    }

    protected function riderRecipientQuery(array $selectedUserIds, array $selectedZoneIds): Builder
    {
        $query = User::query()
            ->select('users.id')
            ->join('delivery_boys', 'delivery_boys.user_id', '=', 'users.id')
            ->whereNull('delivery_boys.deleted_at')
            ->orderBy('users.id');

        // userMaps stores users.id (the admin picker returns users.id), so filter on that.
        if (! empty($selectedUserIds)) {
            $query->whereIn('users.id', $selectedUserIds);
        }

        if (! empty($selectedZoneIds)) {
            $query->whereIn('delivery_boys.delivery_zone_id', $selectedZoneIds);
        }

        return $query->distinct();
    }

    protected function validateSelectedUserIds(NotificationAudienceTypeEnum $audienceType, array $userIds): void
    {
        if (empty($userIds)) {
            return;
        }

        $count = match ($audienceType) {
            NotificationAudienceTypeEnum::CUSTOMER => User::whereIn('id', $userIds)->count(),
            NotificationAudienceTypeEnum::SELLER => Seller::whereIn('user_id', $userIds)->count(),
            NotificationAudienceTypeEnum::RIDER => DeliveryBoy::whereIn('user_id', $userIds)->count(),
        };

        if ($count !== count($userIds)) {
            throw ValidationException::withMessages([
                'user_ids' => ['One or more selected users are invalid for the selected audience type.'],
            ]);
        }
    }

    protected function normalizeIds(array $ids): array
    {
        return collect($ids)
            ->filter(fn ($id) => ! is_null($id) && $id !== '')
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();
    }
}
