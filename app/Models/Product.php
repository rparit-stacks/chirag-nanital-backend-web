<?php

namespace App\Models;

use App\Enums\Order\OrderItemStatusEnum;
use App\Enums\Product\ProductStatusEnum;
use App\Enums\Product\ProductFilterEnum;
use App\Enums\Product\ProductVarificationStatusEnum;
use App\Enums\SpatieMediaCollectionName;
use App\Enums\Store\StoreVerificationStatusEnum;
use App\Enums\Store\StoreVisibilityStatusEnum;
use App\Enums\SettingTypeEnum;
use App\Services\DeliveryZoneService;
use App\Services\SettingService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Product extends Model implements HasMedia
{
    use SoftDeletes, InteractsWithMedia;

    protected $appends = ['estimated_delivery_time', 'favorite', 'main_image', 'additional_images'];

    protected $fillable = [
        'uuid',
        'seller_id',
        'category_id',
        'brand_id',
        'product_condition_id',
        'provider',
        'provider_product_id',
        'slug',
        'title',
        'product_identity',
        'type',
        'short_description',
        'description',
        'indicator',
        'download_allowed',
        'download_link',
        'minimum_order_quantity',
        'quantity_step_size',
        'total_allowed_quantity',
        'is_inclusive_tax',
        'hsn_code',
        'is_returnable',
        'returnable_days',
        'is_cancelable',
        'cancelable_till',
        'is_attachment_required',
        'attachment_mode',
        'base_prep_time',
        'status',
        'verification_status',
        'rejection_reason',
        'featured',
        'requires_otp',
        'video_type',
        'video_link',
        'cloned_from_id',
        'tags',
        'custom_fields',
        'warranty_period',
        'guarantee_period',
        'made_in',
        'metadata',
        'image_fit',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'custom_fields' => 'array',
        'base_prep_time' => 'integer',
    ];

    /**
     * Scope to eager-load category and its immediate parent to reduce queries
     * when accessing the hierarchy key. Deeper ancestors are supported and
     * will be resolved dynamically during traversal (no fixed depth limit).
     */
    public function scopeWithCategoryHierarchy(Builder $query): Builder
    {
        return $query->with(['category.parent']);
    }

    /**
     * Accessor to get the category hierarchy key for a product, composed from
     * the root category down to the product's category using category slugs.
     * Example: "electronics/mobiles/android".
     */
    public function getCategoryHierarchyKeyAttribute(): ?array
    {
        // Ensure base relation is available; deeper ancestors are traversed
        // dynamically to support unlimited parent levels.
        if (!$this->relationLoaded('category')) {
            $this->loadMissing('category');
        }

        $category = $this->category;
        if (!$category) {
            return null;
        }

        $result = [];
        $current = &$result;

        $limit = 8;
        $count = 0;
        $visited = []; // Track visited category IDs

        while ($category && $count < $limit) {

            // 🚨 Break if circular reference detected
            if (in_array($category->id, $visited)) {
                break;
            }

            $visited[] = $category->id;

            $current['id'] = $category->id;

            if ($category->parent && $count < $limit - 1) {
                $current['child'] = [];
                $current = &$current['child'];
            }

            $category = $category->parent;
            $count++;
        }

        return $result;
    }

    public function getEstimatedDeliveryTimeAttribute()
    {
        // If user coordinates or zone info are not available, return null
        if (!isset($this->user_latitude) || !isset($this->user_longitude) || !isset($this->zone_info)) {
            return null;
        }

        // Get base preparation time from product
        $basePrepTime = $this->base_prep_time ?? 0;

        // Get delivery time per km and buffer time from zone info
        $deliveryTimePerKm = $this->zone_info['delivery_time_per_km'] ?? 0;
        $bufferTime = $this->zone_info['buffer_time'] ?? 0;

        $distance = null;
        // Walk every loaded store-variant and keep the shortest distance so ETA reflects the
        // store the customer will actually be served from (mirrors the nearest-store selection
        // used for pricing/stock).
        foreach ($this->variants as $variant) {
            foreach ($variant->storeProductVariants as $storeProductVariant) {
                $store = $storeProductVariant->store;
                if ($store && isset($store->latitude) && isset($store->longitude)) {
                    $d = DeliveryZoneService::calculateDistance(
                        $this->user_latitude,
                        $this->user_longitude,
                        $store->latitude,
                        $store->longitude
                    );
                    $distance = $distance === null ? $d : min($distance, $d);
                }
            }
        }

        if ($distance === null) {
            return null;
        }
        // Calculate estimated time (in minutes)
        $estimatedTime = $basePrepTime + ($distance * $deliveryTimePerKm) + $bufferTime;
        // Round to the nearest minute
        return ceil($estimatedTime);
    }

    /**
     * Scope: Apply product-specific filters such as featured, low_stock, out_of_stock.
     * This reuses the same stock aggregation logic needed by multiple controllers.
     */
    public function scopeApplyProductFilter(Builder $query, ?string $filter): Builder
    {
        if (empty($filter) || !in_array($filter, ProductFilterEnum::values(), true)) {
            return $query;
        }

        try {
            // Stock based filters
            if (in_array($filter, [ProductFilterEnum::LOW_STOCK(), ProductFilterEnum::OUT_OF_STOCK()], true)) {
                $stockSub = DB::table('product_variants')
                    ->join('store_product_variants', 'store_product_variants.product_variant_id', '=', 'product_variants.id')
                    ->select('product_variants.product_id', DB::raw('COALESCE(SUM(store_product_variants.stock), 0) as total_stock'))
                    ->groupBy('product_variants.product_id');

                // Join aggregated stock to products and select base columns
                $query->leftJoinSub($stockSub, 'product_stock_totals', function ($join) {
                    $join->on('product_stock_totals.product_id', '=', 'products.id');
                })
                    ->select('products.*', DB::raw('COALESCE(product_stock_totals.total_stock, 0) as total_stock'));

                if ($filter === ProductFilterEnum::OUT_OF_STOCK()) {
                    $query->whereRaw('COALESCE(product_stock_totals.total_stock, 0) <= 0');
                } elseif ($filter === ProductFilterEnum::LOW_STOCK()) {
                    // Retrieve lowStockLimit from system settings
                    $lowStockLimit = 0;
                    try {
                        $settingService = app(SettingService::class);
                        $systemSettingsResource = $settingService->getSettingByVariable(SettingTypeEnum::SYSTEM());
                        $systemSettings = $systemSettingsResource?->toArray(request())['value'] ?? [];
                        $lowStockLimit = (int)($systemSettings['lowStockLimit'] ?? 0);
                    } catch (\Throwable $e) {
                        $lowStockLimit = 0;
                    }

                    if ($lowStockLimit > 0) {
                        $query->whereRaw('COALESCE(product_stock_totals.total_stock, 0) > 0')
                            ->whereRaw('COALESCE(product_stock_totals.total_stock, 0) <= ?', [$lowStockLimit]);
                    } else {
                        // Guardrail: if not configured, return none for low stock
                        $query->whereRaw('1 = 0');
                    }
                }
            } elseif ($filter === ProductFilterEnum::FEATURED()) {
                $query->where('featured', '1');
            }
        } catch (\Throwable $e) {
            // Ignore filter errors to avoid breaking listing endpoints
        }

        return $query;
    }

    public function getFavoriteAttribute(): ?array
    {
        $user = Auth::guard('sanctum')->user();
        if (!$user) {
            return null; // User isn't authenticated
        }

        $wishlistItem = WishlistItem::with(['wishlist', 'variant', 'store'])
            ->whereHas('wishlist', function ($query) use ($user) {
                $query->where('user_id', $user->id);
            })
            ->where('product_id', $this->id)->get();

        if (!$wishlistItem) {
            return null;
        }
        foreach ($wishlistItem as $item) {
            $items[] = [
                'id' => $item->id,
                'wishlist_id' => $item->wishlist_id,
                'wishlist_title' => $item->wishlist->title,
                'variant_id' => $item->variant->id ?? null,
                'variant_name' => $item->variant->name ?? "",
                'store_id' => $item->store?->id,
                'store_name' => $item->store?->name,
            ];
        }
        return $items ?? null;
    }

    public function getIsSaveForLaterAttribute()
    {
        try {

            $user = Auth::guard('sanctum')->user();
            if (!$user) {
                return null; // User isn't authenticated
            }

            return CartItem::whereHas('cart', function ($query) use ($user) {
                $query->where('user_id', $user->id);
            })->where('product_id', $this->id)->exists();
        } catch (\Throwable $e) {
            Log::error('Error checking if product is saved for later: ' . $e->getMessage());
            return null;
        }
    }

    public function getTagsAttribute(): ?array
    {
        // Ensure tags are stored as a JSON array in the database
        if (isset($this->attributes['tags']) && is_string($this->attributes['tags'])) {
            return json_decode($this->attributes['tags'], true);
        }
        return $this->attributes['tags'] ?? [];
    }

    public
    function getMainImageAttribute(): ?string
    {
        return (!empty($this->getFirstMediaUrl(SpatieMediaCollectionName::PRODUCT_MAIN_IMAGE())) ? $this->getFirstMediaUrl(SpatieMediaCollectionName::PRODUCT_MAIN_IMAGE()) : asset('assets/images/product-placeholder.jpg'));
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'product_id');
    }

    public function hasPendingOrders(): bool
    {
        return $this->orderItems()
            ->whereNot('status', OrderItemStatusEnum::DELIVERED())
            ->exists();
    }

    public function cartItems(): HasMany
    {
        return $this->hasMany(CartItem::class, 'product_id');
    }

    public
    function getAdditionalImagesAttribute(): ?array
    {
        return $this->getMedia(SpatieMediaCollectionName::PRODUCT_ADDITIONAL_IMAGE())
            ->map(function ($media) {
                return $media->getUrl();
            })->toArray();
    }

    public function getItemCountInCartAttribute()
    {
        return CartItem::where('product_id', $this->id)
            ->sum('quantity');
    }

    public
    function setTitleAttribute($value): void
    {
        $this->attributes['title'] = $value;
        $this->attributes['slug'] = generateUniqueSlug(model: self::class, title: $value, id: $this->id ?? null);
        if (empty($this->id)) {
            $this->attributes['uuid'] = (string)Str::uuid();
        }
    }

    public
    function setStatusAttribute($value): void
    {
        $this->attributes['status'] = $value;
    }

    public
    function setVerificationStatus($value): void
    {
        $this->attributes['verification_status'] = $value;
    }

    public
    function getVideoLinkAttribute($value)
    {
        if ($this->video_type === 'self_hosted') {
            return $this->getFirstMediaUrl(SpatieMediaCollectionName::PRODUCT_VIDEO());
        }
        return $value;
    }

    public
    function faqs(): HasMany
    {
        return $this->hasMany(ProductFaq::class, 'product_id');
    }

    public
    function reviews(): HasMany
    {
        return $this->hasMany(Review::class, 'product_id');
    }

    public
    function taxClasses(): BelongsToMany
    {
        return $this->belongsToMany(TaxClass::class, 'product_taxes')->with('taxRates');
    }

    public
    function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public
    function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public
    function productCondition(): BelongsTo
    {
        return $this->belongsTo(ProductCondition::class);
    }

    public
    function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    public
    function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderByDesc('is_default');
    }

    public
    function variantAttributes(): HasMany
    {
        return $this->hasMany(ProductVariantAttribute::class);
    }

    public
    function customProductSections(): HasMany
    {
        return $this->hasMany(CustomProductSection::class)->orderBy('sort_order');
    }

    /**
     * Get formatted variant attributes grouped by attribute name
     *
     * @return array
     */
    public
    function getFormattedVariantAttributes(): array
    {
        // Load variant attributes with their relationships
        $this->load(['variantAttributes.attribute', 'variantAttributes.attributeValue']);

        $attributes = [];
        $attributeMap = [];

        foreach ($this->variantAttributes as $variantAttribute) {
            $attribute = $variantAttribute->attribute;
            $attributeValue = $variantAttribute->attributeValue;

            if (!$attribute || !$attributeValue) {
                continue;
            }

            $attributeName = $attribute->title;
            $attributeSlug = $attribute->slug;
            $valueTitle = $attributeValue->title;
            $swatcheValue = $attributeValue->swatche_value;

            // If this attribute is not in our map yet, add it
            if (!isset($attributeMap[$attributeSlug])) {
                $attributeMap[$attributeSlug] = count($attributes);
                $attributes[] = [
                    'name' => $attributeName,
                    'slug' => $attributeSlug,
                    'swatche_type' => $attribute->swatche_type,
                    'values' => [],
                    'swatch_values' => []
                ];
            }

            // Add the value if it's not already in the array
            $index = $attributeMap[$attributeSlug];
            if (!in_array($valueTitle, $attributes[$index]['values'])) {
                $attributes[$index]['values'][] = $valueTitle;
                $attributes[$index]['swatch_values'][] = [
                    'value' => $valueTitle,
                    'swatch' => $swatcheValue
                ];
            }
        }

        return $attributes;
    }


    public function scopeApplySorting(Builder $query, ?string $sort, array $storeIds = []): Builder
    {
        // If a sort option is explicitly provided, clear any previous ORDER BY clauses
        // so the requested sort isn't overridden by existing section-type ordering.
        if (!is_null($sort) && $sort !== '') {
            $query->reorder();
        }
        switch ($sort) {
            case 'price_asc':
                if (empty($storeIds)) {
                    $query->orderBy('id', 'desc');
                    break;
                }
                $priceSub = DB::table('product_variants')
                    ->join('store_product_variants', 'product_variants.id', '=', 'store_product_variants.product_variant_id')
                    ->whereIn('store_product_variants.store_id', $storeIds)
                    ->where('product_variants.is_default', 1)
                    ->select('product_variants.product_id', DB::raw('MIN(store_product_variants.special_price) as price'))
                    ->groupBy('product_variants.product_id');

                $query->joinSub($priceSub, 'pv_prices', fn($join) => $join->on('pv_prices.product_id', '=', 'products.id'))
                    ->orderBy('pv_prices.price', 'asc')
                    ->select('products.*');
                break;

            case 'price_desc':
                if (empty($storeIds)) {
                    $query->orderBy('id', 'desc');
                    break;
                }
                $priceSub = DB::table('product_variants')
                    ->join('store_product_variants', 'product_variants.id', '=', 'store_product_variants.product_variant_id')
                    ->whereIn('store_product_variants.store_id', $storeIds)
                    ->where('product_variants.is_default', 1)
                    ->select('product_variants.product_id', DB::raw('MAX(store_product_variants.special_price) as price'))
                    ->groupBy('product_variants.product_id');

                $query->joinSub($priceSub, 'pv_prices', fn($join) => $join->on('pv_prices.product_id', '=', 'products.id'))
                    ->orderBy('pv_prices.price', 'desc')
                    ->select('products.*');
                break;

            case 'avg_rated':
                $query->withAvg('reviews', 'rating')
                    ->orderBy('reviews_avg_rating', 'desc');
                break;

            case 'best_seller':
                $query->withCount(['orderItems' => fn($q) => $q->where('order_items.status', OrderItemStatusEnum::DELIVERED())])
                    ->orderBy('order_items_count', 'desc');
                break;

            case 'featured':
                $query->where('featured', "1")->orderBy('id', 'desc');
                break;

            case 'relevance':
            default:
                $query->orderBy('id', 'desc');
                break;
        }

        return $query;
    }

    public static function getStoreIdsInZone(array $zoneInfo, ?string $storeSlug = null): array
    {
        if (!$zoneInfo['exists']) {
            return [];
        }

        $storeQuery = Store::whereHas('zones', function ($q) use ($zoneInfo) {
            $q->where('delivery_zones.id', $zoneInfo['zone_id']);
        })
            ->where('verification_status', StoreVerificationStatusEnum::APPROVED())
            ->where('visibility_status', StoreVisibilityStatusEnum::VISIBLE());

        if ($storeSlug) {
            $store = (clone $storeQuery)->where('slug', $storeSlug)->first();
            return $store ? [$store->id] : [];
        }

        return $storeQuery->pluck('id')->toArray();
    }

    /**
     * Get all child category IDs recursively
     */
    private static function getAllChildCategoryIds(int $categoryId): array
    {
        $childIds = [];
        $children = Category::where('parent_id', $categoryId)->pluck('id')->toArray();

        foreach ($children as $childId) {
            $childIds[] = $childId;
            $grandChildIds = self::getAllChildCategoryIds($childId);
            $childIds = array_merge($childIds, $grandChildIds);
        }

        return $childIds;
    }

    /**
     * Scope to get products by location
     */
    public static function scopeByLocation($zoneInfo, $query, $filter = []): Builder
    {
        $storeIds = self::getStoreIdsInZone($zoneInfo, $filter['store'] ?? null);
        if (empty($storeIds)) {
            return $query->whereRaw('1 = 0');
        }

        // Apply individual filters in small, focused helpers
        self::applyCategoryFilter($query, $filter);
        self::applyBrandFilter($query, $filter);
        self::applyAttributeValueFilter($query, $filter);
        self::applyExcludeFilter($query, $filter);
        self::applySearchFilter($query, $filter);

        // Base constraints common to all product queries
        self::applyBaseConstraints($query);

        // Sorting depends on storeIds
        $query->applySorting($filter['sort'] ?? null, $storeIds);

        // Eager-load only store-available variants and ensure availability
        return self::withVariantRelations($query, $storeIds);
    }

    /**
     * Get the categories associated with the product.
     */
    public
    function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'category_product')
            ->withTimestamps();
    }

    /**
     * Apply category filter with optional inclusion of all child categories.
     */
    private static function applyCategoryFilter(Builder $query, array $filter): void
    {
        if (empty($filter['categories'])) {
            return;
        }

        $categoryIds = Category::whereIn('slug', $filter['categories'])->pluck('id')->toArray();

        if (!empty($filter['include_child_categories'])) {
            $allCategoryIds = $categoryIds;
            foreach ($categoryIds as $categoryId) {
                $childIds = self::getAllChildCategoryIds($categoryId);
                $allCategoryIds = array_merge($allCategoryIds, $childIds);
            }
            $categoryIds = array_unique($allCategoryIds);
        }

        if (!empty($categoryIds)) {
            $query->whereIn('category_id', $categoryIds);
        }
    }

    /**
     * Apply brand filter by slugs.
     */
    private static function applyBrandFilter(Builder $query, array $filter): void
    {
        if (empty($filter['brands'])) {
            return;
        }

        $brandIds = Brand::whereIn('slug', $filter['brands'])->pluck('id')->toArray();
        if (!empty($brandIds)) {
            $query->whereIn('brand_id', $brandIds);
        }
    }

    /**
     * Apply attribute value filter on product variant attributes.
     */
    private static function applyAttributeValueFilter(Builder $query, array $filter): void
    {
        if (empty($filter['attribute_values'])) {
            return;
        }

        $attrValueIds = is_array($filter['attribute_values'])
            ? array_values(array_filter(array_map('intval', $filter['attribute_values'])))
            : [(int)$filter['attribute_values']];

        if (!empty($attrValueIds)) {
            $query->whereHas('variantAttributes', function ($q) use ($attrValueIds) {
                $q->whereIn('global_attribute_value_id', $attrValueIds);
            });
        }
    }

    /**
     * Apply exclusion filter for product slugs.
     */
    private static function applyExcludeFilter(Builder $query, array $filter): void
    {
        if (empty($filter['exclude_product'])) {
            return;
        }

        $exclude = $filter['exclude_product'];
        if (is_array($exclude)) {
            $query->whereNotIn('slug', $exclude);
        } else {
            $query->whereNot('slug', $exclude);
        }
    }

    /**
     * Apply text search across product fields and related models.
     */
    private static function applySearchFilter(Builder $query, array $filter): void
    {
        if (empty($filter['search'])) {
            return;
        }

        $searchTerm = $filter['search'];
        $query->where(function ($q) use ($searchTerm) {
            $q->where('title', 'LIKE', "%{$searchTerm}%")
                ->orWhere('description', 'LIKE', "%{$searchTerm}%")
                ->orWhere('short_description', 'LIKE', "%{$searchTerm}%")
                ->orWhere('tags', 'LIKE', "%{$searchTerm}%")
                ->orWhereHas('category', function ($categoryQuery) use ($searchTerm) {
                    $categoryQuery->where('title', 'LIKE', "%{$searchTerm}%");
                })
                ->orWhereHas('categories', function ($categoriesQuery) use ($searchTerm) {
                    $categoriesQuery->where('title', 'LIKE', "%{$searchTerm}%");
                })
                ->orWhereHas('brand', function ($brandsQuery) use ($searchTerm) {
                    $brandsQuery->where('title', 'LIKE', "%{$searchTerm}%");
                });
        });
    }

    /**
     * Apply base constraints for product visibility and approval.
     */
    private static function applyBaseConstraints(Builder $query): void
    {
        $query->where('verification_status', ProductVarificationStatusEnum::APPROVED());
        $query->where('status', ProductStatusEnum::ACTIVE());
    }

    /**
     * Eager-load variants filtered by stores and ensure availability via whereHas.
     */
    private static function withVariantRelations(Builder $query, array $storeIds): Builder
    {
        return $query->with([
            'variants' => function ($q) use ($storeIds) {
                $q->whereHas('storeProductVariants', function ($sq) use ($storeIds) {
                    $sq->whereIn('store_id', $storeIds);
                });
            },
            'variants.storeProductVariants' => function ($q) use ($storeIds) {
                $q->whereIn('store_id', $storeIds);
            },
            'variants.storeProductVariants.store',
            'variants.attributes.attribute',
            'variants.attributes.attributeValue',
            'variants.storeVariantAddons' => function ($q) use ($storeIds) {
                $q->whereIn('store_id', $storeIds);
            },
            'variants.storeVariantAddons.addonGroup',
            'variants.storeVariantAddons.addonItem',
            'variantAttributes.attribute',
            'variantAttributes.attributeValue'
        ])->whereHas('variants.storeProductVariants', function ($q) use ($storeIds) {
            $q->whereIn('store_id', $storeIds);
        });
    }

    /**
     * Get products by location with pagination
     *
     * Note: This method uses the scopeByLocation method through Laravel's dynamic scope feature.
     * When you call self::byLocation(), Laravel automatically routes to the scopeByLocation() method.
     */
    public
    static function getProductsByLocation(float $latitude, float $longitude, int $perPage = 15, array $filter = []): LengthAwarePaginator
    {
        // Get zones at the given coordinates
        $zoneInfo = DeliveryZoneService::getZonesAtPoint($latitude, $longitude);

        // Build a base query that excludes category/brand slug filters
        // so we can compute unique IDs from the unfiltered result set.
        $baseFilter = $filter;
        unset($baseFilter['categories'], $baseFilter['brands']);

        $baseQuery = self::scopeByLocation(zoneInfo: $zoneInfo, query: self::query(), filter: $baseFilter);

        // Collect unique category and brand IDs, limit to 50
        $categoryIds = self::getCategoryIds($baseQuery);

        $filteredQuery = self::scopeByLocation(
            zoneInfo: $zoneInfo,
            query: self::query(),
            filter: $filter
        );
        $brandIds = self::getBrandIds($filteredQuery);

        $products = $filteredQuery
            ->orderBy('title')
            ->paginate($perPage);

        // Store the user's latitude and longitude in each product for delivery time calculation
        foreach ($products as $product) {
            $product->user_latitude = $latitude;
            $product->user_longitude = $longitude;
            $product->zone_info = $zoneInfo;
            $product->preferNearestStoreVariants($latitude, $longitude);
        }
        $relatedKeywords = !empty($filter['search'])
            ? self::getRelatedKeywords($filter['search'])
            : [];
        // Attach supplemental data to paginator
        $products->related_keywords = $relatedKeywords;
        $products->category_ids = $categoryIds;
        $products->brand_ids = $brandIds;
        return $products;
    }

    private static function getCategoryIds($query): array
    {
        return (clone $query)
            ->distinct()
            ->pluck('category_id')
            ->filter()
            ->unique()
            ->map(fn($id) => (int)$id)
            ->take(50)
            ->values()
            ->toArray();
    }

    private static function getBrandIds($query): array
    {
        return (clone $query)
            ->distinct()
            ->pluck('brand_id')
            ->filter()
            ->unique()
            ->map(fn($id) => (int)$id)
            ->take(50)
            ->values()
            ->toArray();
    }

    private static function getRelatedKeywords(string $searchTerm): array
    {
        return self::query()
            ->where('tags', 'LIKE', "%{$searchTerm}%")
            ->select('tags')
            ->limit(20)
            ->pluck('tags')
            ->flatMap(function ($tags) {
                if (is_array($tags)) return $tags;
                if (is_null($tags)) return [];

                if (is_string($tags) && str_starts_with(trim($tags), '[')) {
                    $decoded = json_decode($tags, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        return $decoded;
                    }
                }

                return array_map('trim', explode(',', $tags));
            })
            ->filter(fn($tag) => is_string($tag) && stripos($tag, $searchTerm) !== false)
            ->unique()
            ->take(10)
            ->values()
            ->toArray();
    }

    public static function getProductByLocation(float $latitude, float $longitude, $id): ?Model
    {
        // Get zones at the given coordinates
        $zoneInfo = DeliveryZoneService::getZonesAtPoint($latitude, $longitude);
        // In Laravel, when you define a method with the prefix 'scope' (like scopeByLocation),
        // you can call it without the prefix (as just byLocation)
        $product = self::scopeByLocation(zoneInfo: $zoneInfo, query: self::query()->with([
            'variants.storeProductVariants',
            'variants.attributes.attribute',
            'variants.attributes.attributeValue',
            'variants.storeVariantAddons.addonGroup',
            'variants.storeVariantAddons.addonItem',
            'variantAttributes.attribute',
            'variantAttributes.attributeValue',
            'customProductSections.fields',
        ]))
            ->where('id', $id)
            ->where('verification_status', ProductVarificationStatusEnum::APPROVED())
            ->get()->first();
        if (!empty($product)) {
            $product->user_latitude = $latitude;
            $product->user_longitude = $longitude;
            $product->zone_info = $zoneInfo;
            $product->preferNearestStoreVariants($latitude, $longitude);
        }
        return $product;
    }

    /**
     * Reorder each loaded variant's storeProductVariants so the nearest in-stock store wins.
     *
     * Stores are ranked by (has_stock DESC, distance_km ASC). Downstream resources read the
     * first row of `storeProductVariants` for price/stock/store attribution, so this is where
     * "same seller, multiple stores in one zone" is resolved — no extra SQL, pure in-memory
     * re-sort of an already eager-loaded collection.
     */
    public function preferNearestStoreVariants(float $userLatitude, float $userLongitude): void
    {
        if (!$this->relationLoaded('variants')) {
            return;
        }

        foreach ($this->variants as $variant) {
            if (!$variant->relationLoaded('storeProductVariants')) {
                continue;
            }

            $sorted = $variant->storeProductVariants
                ->sort(function ($a, $b) use ($userLatitude, $userLongitude) {
                    $aInStock = ((int) ($a->stock ?? 0)) > 0 ? 1 : 0;
                    $bInStock = ((int) ($b->stock ?? 0)) > 0 ? 1 : 0;
                    if ($aInStock !== $bInStock) {
                        return $bInStock <=> $aInStock;
                    }
                    return self::storeVariantDistance($a, $userLatitude, $userLongitude)
                        <=> self::storeVariantDistance($b, $userLatitude, $userLongitude);
                })
                ->values();

            $variant->setRelation('storeProductVariants', $sorted);
        }
    }

    /**
     * Resolve the haversine distance from the user to a store-product-variant's store,
     * returning PHP_FLOAT_MAX when coordinates are missing so such rows sort last.
     */
    private static function storeVariantDistance($storeProductVariant, float $lat, float $lon): float
    {
        $store = $storeProductVariant->store ?? null;
        if (!$store || $store->latitude === null || $store->longitude === null) {
            return PHP_FLOAT_MAX;
        }

        return DeliveryZoneService::calculateDistance(
            $lat,
            $lon,
            (float) $store->latitude,
            (float) $store->longitude
        );
    }

    protected
    static function booted(): void
    {
        static::deleting(function ($product) {
            // Get all variants for the product
            foreach ($product->variants as $variant) {
                // Delete attributes related to the variant
                $variant->attributes()->delete();

                // Delete store product variants related to the variant
                $variant->storeProductVariants()->delete();

                // Delete the variant itself (will be soft deleted)
                $variant->delete();
            }
        });
        static::forceDeleted(function ($product) {
            $product->clearMediaCollection();
        });
    }
}
