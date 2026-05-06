<?php

namespace App\Services;

use App\Enums\SpatieMediaCollectionName;
use App\Enums\Store\StoreVerificationStatusEnum;
use App\Enums\Store\StoreVisibilityStatusEnum;
use App\Events\Store\StoreCreated;
use App\Events\Store\StoreUpdated;
use App\Http\Requests\Store\StoreStoreRequest;
use App\Http\Requests\Store\UpdateStoreRequest;
use App\Models\Country;
use App\Models\Seller;
use App\Models\Store;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class StoreService
{
    public function listForSeller(Seller $seller, ?string $search, int $perPage = 15, $filters = []): LengthAwarePaginator
    {
        $query = Store::query()
            ->where('seller_id', $seller->id)
            ->orderByDesc('id');

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (!empty($filters['visibility_status'])) {
            $query->where('visibility_status', $filters['visibility_status']);
        }
        if (!empty($filters['verification_status'])) {
            $query->where('verification_status', $filters['verification_status']);
        }

        if ($search !== null && trim($search) !== '') {
            $q = trim($search);
            $query->where(function ($sub) use ($q) {
                $sub->where('name', 'like', "%$q%")
                    ->orWhere('description', 'like', "%$q%")
                    ->orWhere('address', 'like', "%$q%");
            });
        }

        return $query->paginate($perPage);
    }

    /**
     * Create a store for seller using validated request data.
     */
    public function createForSeller(StoreStoreRequest $request, Seller $seller): Store
    {
        return DB::transaction(function () use ($request, $seller) {
            $validated = $request->safe()->except('address_proof', 'voided_check');
            $validated['seller_id'] = $seller->id;

            $isInZone = DeliveryZoneService::getZonesAtPoint($validated['latitude'], $validated['longitude']);
            if ($isInZone['exists'] === false) {
                throw new \RuntimeException('Store location is not within any delivery zone');
            }

            $country = Country::where('name', $validated['country'])->firstOrFail();
            if (!empty($country->phonecode)) {
                $validated['country_code'] = $country->phonecode;
                $validated['currency_code'] = $country->currency;
            }

            $validated['verification_status'] = StoreVerificationStatusEnum::NOT_APPROVED();
            $validated['visibility_status'] = StoreVisibilityStatusEnum::DRAFT();

            $store = Store::create($validated);

            if (!empty($isInZone['zone_id'])) {
                $store->zones()->sync([$isInZone['zone_id']]);
            }

            if ($request->hasFile('store_logo')) {
                SpatieMediaService::upload($store, SpatieMediaCollectionName::STORE_LOGO());
            }
            if ($request->hasFile('store_banner')) {
                SpatieMediaService::upload($store, SpatieMediaCollectionName::STORE_BANNER());
            }
            if ($request->hasFile('address_proof')) {
                SpatieMediaService::upload($store, SpatieMediaCollectionName::ADDRESS_PROOF());
            }
            if ($request->hasFile('voided_check')) {
                SpatieMediaService::upload($store, SpatieMediaCollectionName::VOIDED_CHECK());
            }

            event(new StoreCreated($store));

            return $store;
        });
    }

    /**
     * Update an existing seller-owned store.
     */
    public function updateForSeller(UpdateStoreRequest $request, Store $store): Store
    {
        return DB::transaction(function () use ($request, $store) {
            $validated = $request->validated();

            $isInZone = DeliveryZoneService::getZonesAtPoint($validated['latitude'], $validated['longitude']);
            if ($isInZone['exists'] === false) {
                throw new \RuntimeException('Store location is not within any delivery zone');
            }

            $country = Country::where('name', $validated['country'])->firstOrFail();
            if (!empty($country->phonecode)) {
                $validated['country_code'] = $country->phonecode;
                $validated['currency_code'] = $country->currency;
            }

            $store->update($validated);

            if (!empty($isInZone['zone_id'])) {
                $store->zones()->sync([$isInZone['zone_id']]);
            }

            if ($request->hasFile('store_logo')) {
                SpatieMediaService::update($request, $store, SpatieMediaCollectionName::STORE_LOGO());
            }
            if ($request->hasFile('store_banner')) {
                SpatieMediaService::update($request, $store, SpatieMediaCollectionName::STORE_BANNER());
            }

            event(new StoreUpdated($store));

            return $store;
        });
    }

    public function deleteForSeller(Store $store): void
    {
        $store->delete();
    }

    public function updateStatusForSeller(Store $store, string $status): Store
    {
        $store->status = $status;
        $store->save();
        return $store;
    }

    /**
     * Ensure the given store belongs to the seller; throw if not found/owned.
     */
    public function findOwnedOrFail(Seller $seller, int $id): Store
    {
        $store = Store::findOrFail($id);
        if ((int)$store->seller_id !== (int)$seller->id) {
            throw new ModelNotFoundException('Store not found');
        }
        return $store;
    }

    /**
     * Get available stores for given zone IDs with optional search and pagination.
     *
     * @param array $zoneIds Array of zone IDs to filter available stores
     * @param string|null $search Optional search term to filter stores by name, description, address, or seller name
     * @param int $perPage Number of results per page
     * @return LengthAwarePaginator Paginated list of available stores
     */
    public function getAvailableStoresByZoneIds(array $zoneIds, ?string $search = null, int $perPage = 15): LengthAwarePaginator
    {
        // Normalize zone IDs to unique integers
        $zoneIds = array_values(array_unique(array_filter(array_map('intval', $zoneIds))));

        // Build base query for available stores
        $query = $this->baseAvailabilityQuery($zoneIds);

        // Apply optional search filter
        if (!empty($search)) {
            $this->applySearch($query, $search);
        }

        // Order by name
        $query->orderBy('name');

        return $query->paginate($perPage);
    }

    /**
     * Build base store query constrained by verification/visibility status and zone availability.
     *
     * A store is considered available if it is approved and visible in the specified zones.
     */
    protected function baseAvailabilityQuery(array $zoneIds): Builder
    {
        $storeQuery = Store::query()
            ->where('verification_status', StoreVerificationStatusEnum::APPROVED())
            ->where('visibility_status', StoreVisibilityStatusEnum::VISIBLE());
        if (!empty($zoneIds)) {
            $storeQuery->whereHas('zones', function ($q) use ($zoneIds) {
                $q->whereIn('delivery_zones.id', $zoneIds);
            });
        }
        return $storeQuery;
    }

    /**
     * Apply simple search on the store query.
     * Searches across store name, description, address, and seller name.
     */
    protected function applySearch(Builder $query, string $search): void
    {
        $query->where(function ($q) use ($search) {
            $q->where('name', 'LIKE', "%{$search}%")
                ->orWhere('description', 'LIKE', "%{$search}%")
                ->orWhere('address', 'LIKE', "%{$search}%")
                ->orWhereHas('seller.user', function ($sq) use ($search) {
                    $sq->where('name', 'LIKE', "%{$search}%");
                });
        });
    }

    /**
     * Create an empty paginator.
     */
    protected function emptyPaginator(int $perPage): LengthAwarePaginator
    {
        return new \Illuminate\Pagination\LengthAwarePaginator([], 0, $perPage, 1, [
            'path' => request()->url(),
            'query' => request()->query(),
        ]);
    }
}

