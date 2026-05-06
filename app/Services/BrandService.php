<?php

namespace App\Services;

use App\Enums\BrandStatusEnum;
use App\Enums\Product\ProductStatusEnum;
use App\Enums\Product\ProductVarificationStatusEnum;
use App\Models\Brand;
use App\Traits\HasZoneAvailability;
use Illuminate\Database\Eloquent\Builder;

class BrandService
{
    use HasZoneAvailability;

    /**
     * Build base brand query constrained by active status and availability in given stores.
     *
     * A brand is considered available if it has at least one approved and active product
     * that has variants available in any of the specified stores.
     */
    protected function baseAvailabilityQuery(array $storeIds): Builder
    {
        return Brand::query()
            ->where('status', BrandStatusEnum::ACTIVE())
            ->distinct()
            ->whereHas('products', function ($productQuery) use ($storeIds) {
                $productQuery->where('verification_status', ProductVarificationStatusEnum::APPROVED())
                    ->where('status', ProductStatusEnum::ACTIVE())
                    ->whereHas('variants.storeProductVariants', function ($variantQuery) use ($storeIds) {
                        $variantQuery->whereIn('store_id', $storeIds);
                    });
            });
    }
}
