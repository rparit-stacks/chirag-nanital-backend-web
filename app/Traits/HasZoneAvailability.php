<?php

namespace App\Traits;

use App\Enums\Store\StoreVerificationStatusEnum;
use App\Enums\Store\StoreVisibilityStatusEnum;
use App\Models\Store;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

trait HasZoneAvailability
{
    /**
     * Get available records for given zone IDs with optional search and pagination.
     * Subclasses define the base query and search logic.
     */
    public function getAvailableByZoneIds(array $zoneIds, ?string $search = null, int $perPage = 15): LengthAwarePaginator
    {
        $zoneIds = array_values(array_unique(array_filter(array_map('intval', $zoneIds))));

        $storeQuery = Store::query()
            ->where('verification_status', StoreVerificationStatusEnum::APPROVED())
            ->where('visibility_status', StoreVisibilityStatusEnum::VISIBLE());

        if (!empty($zoneIds)) {
            $storeQuery->whereHas('zones', fn($q) => $q->whereIn('delivery_zones.id', $zoneIds));
        }

        $storeIds = $storeQuery->pluck('id')->toArray();

        if (empty($storeIds)) {
            return $this->emptyPaginator($perPage);
        }

        $query = $this->baseAvailabilityQuery($storeIds);

        if (!empty($search)) {
            $this->applySearch($query, $search);
        }

        return $query->orderBy('title')->paginate($perPage);
    }

    abstract protected function baseAvailabilityQuery(array $storeIds): Builder;

    protected function applySearch(Builder $query, string $search): void
    {
        $query->where(fn($q) => $q
            ->where('title', 'LIKE', "%{$search}%")
            ->orWhere('description', 'LIKE', "%{$search}%")
        );
    }

    protected function emptyPaginator(int $perPage): LengthAwarePaginator
    {
        return new LengthAwarePaginator([], 0, $perPage, 1, [
            'path'  => request()->url(),
            'query' => request()->query(),
        ]);
    }
}
