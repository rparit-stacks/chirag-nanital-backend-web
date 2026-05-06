<?php

namespace App\Services;

use App\Enums\CategoryStatusEnum;
use App\Enums\Product\ProductStatusEnum;
use App\Enums\Product\ProductVarificationStatusEnum;
use App\Models\Category;
use App\Traits\HasZoneAvailability;

class CategoryService
{
    use HasZoneAvailability;
    public static function getCategoriesWithParent()
    {
        return Category::select('id', 'parent_id', 'title', 'requires_approval')->where('status', CategoryStatusEnum::ACTIVE())->get()->map(function ($category) {
            return [
                'id' => (string) $category->id,
                'parent' => $category->parent_id ? (string) $category->parent_id : '#',
                'text' => $category->title . ($category->requires_approval ? ' <small class="text-azure">(Requires Admin Approval)</small>' : ''),
            ];
        });
    }

    /**
     * Build base category query constrained by active status and availability in given stores.
     *
     * A category is considered available if it has at least one approved and active product
     * that has variants available in any of the specified stores. This also includes products
     * from child categories.
     */
    protected function baseAvailabilityQuery(array $storeIds): \Illuminate\Database\Eloquent\Builder
    {
        return Category::query()
            ->where('status', CategoryStatusEnum::ACTIVE())
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
