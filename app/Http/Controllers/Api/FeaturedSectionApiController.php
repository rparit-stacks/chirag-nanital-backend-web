<?php

namespace App\Http\Controllers\Api;

use App\Enums\FeaturedSection\FeaturedSectionTypeEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\FeaturedSection\ValidateProductsRequest;
use App\Http\Resources\FeaturedSectionResource;
use App\Http\Resources\Product\ProductResource;
use App\Models\Category;
use App\Services\DeliveryZoneService;
use App\Services\FeaturedSectionService;
use App\Traits\ZoneAvailability;
use App\Types\Api\ApiResponseType;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

#[Group('Featured Sections')]
class FeaturedSectionApiController extends Controller
{
    use ZoneAvailability;
    public function __construct(private FeaturedSectionService $featuredSectionService) {}

    /**
     * Get featured sections with products.
     */
    #[QueryParameter('per_page', description: 'Number of items per page.', type: 'int', default: 15, example: 15)]
    #[QueryParameter('page', description: 'Page number for pagination.', type: 'int', default: 1, example: 1)]
    #[QueryParameter('section_type', description: 'Filter by section type (newly_added, top_rated, trending, best_seller, featured, on_sale, recommended).', type: 'string', example: 'featured')]
    #[QueryParameter('products_limit', description: 'Limit number of products per section.', type: 'int', default: 10, example: 20)]
    #[QueryParameter('scope_category_slug', description: 'if you pass slug then featured sections will be filtered by category', type: 'string', example: 'apple, amul')]
    #[QueryParameter('latitude', description: 'User latitude for location-based filtering.', type: 'float', example: '37.7749')]
    #[QueryParameter('longitude', description: 'User longitude for location-based filtering.', type: 'float', example: '-122.4194')]
    public function index(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'per_page' => 'sometimes|integer|min:1|max:100',
                'page' => 'sometimes|integer|min:1',
                'section_type' => 'sometimes|string|in:newly_added,top_rated,trending,best_seller,featured,on_sale,recommended',
                'products_limit' => 'sometimes|integer|min:1|max:50',
                'scope_category_slug' => 'sometimes|string',
                'latitude' => 'sometimes|required_with:longitude|numeric|between:-90,90',
                'longitude' => 'sometimes|required_with:latitude|numeric|between:-180,180',
            ]);

            $perPage = $request->input('per_page', 15);
            $productsLimit = $request->input('products_limit', 10);
            $latitude = $request->input('latitude');
            $longitude = $request->input('longitude');

            // Validate and get category if scope_category_slug is provided
            if ($request->has('scope_category_slug')) {
                $categorySlug = $request->input('scope_category_slug');
                if (empty($categorySlug)) {
                    return ApiResponseType::sendJsonResponse(
                        success: false,
                        message: 'labels.category_slug_is_required',
                        data: [],
                        status: 422
                    );
                }

                $category = Category::where('slug', $categorySlug)->first();
                if (! $category) {
                    return ApiResponseType::sendJsonResponse(
                        success: false,
                        message: 'labels.category_not_found',
                        data: [],
                        status: 404
                    );
                }
            }

            $result = $this->featuredSectionService->paginateSections(
                perPage: $perPage,
                sectionType: $request->input('section_type'),
                scopeCategorySlug: $request->input('scope_category_slug'),
                latitude: $latitude,
                longitude: $longitude,
            );

            $featuredSections = $result['sections'];
            $zoneInfo = $result['zone_info'];

            if (is_array($zoneInfo) && isset($zoneInfo['exists']) && $zoneInfo['exists'] === false) {
                return ApiResponseType::sendJsonResponse(
                    success: false,
                    message: 'labels.delivery_not_available_at_location',
                    data: [],
                    status: 404
                );
            }

            // Transform using FeaturedSectionResource
            $featuredSections->getCollection()->transform(function ($section) use ($productsLimit, $latitude, $longitude, $zoneInfo) {
                $resource = new FeaturedSectionResource($section);
                $additional = ['products_limit' => $productsLimit];

                // Add location data if provided
                if ($latitude && $longitude && $zoneInfo) {
                    $additional['latitude'] = $latitude;
                    $additional['longitude'] = $longitude;
                    $additional['zone_info'] = $zoneInfo;
                }

                $resource->additional($additional);

                return $resource;
            });

            return ApiResponseType::sendJsonResponse(
                success: true,
                message: 'labels.featured_sections_fetched_successfully',
                data: [
                    'current_page' => $featuredSections->currentPage(),
                    'last_page' => $featuredSections->lastPage(),
                    'per_page' => $featuredSections->perPage(),
                    'total' => $featuredSections->total(),
                    'data' => $featuredSections->items(),
                ]
            );

        } catch (ValidationException $e) {
            return ApiResponseType::sendJsonResponse(
                success: false,
                message: 'labels.validation_failed'.$e->getMessage(),
                data: $e->errors(),
                status: 422
            );
        } catch (\Exception $e) {
            Log::error('FeaturedSectionApiController@index: '.$e->getMessage(), [
                'exception' => $e,
            ]);
            return ApiResponseType::sendJsonResponse(
                success: false,
                message: 'labels.something_went_wrong',
            );
        }
    }

    /**
     * Get a specific featured section by slug.
     */
    #[QueryParameter('products_limit', description: 'Limit number of products.', type: 'int', default: 10, example: 20)]
    #[QueryParameter('latitude', description: 'User latitude for location-based filtering.', type: 'float', example: '37.7749')]
    #[QueryParameter('longitude', description: 'User longitude for location-based filtering.', type: 'float', example: '-122.4194')]
    public function show(Request $request, string $slug): JsonResponse
    {
        try {
            $request->validate([
                'products_limit' => 'sometimes|integer|min:1|max:50',
                'latitude' => 'sometimes|required_with:longitude|numeric|between:-90,90',
                'longitude' => 'sometimes|required_with:latitude|numeric|between:-180,180',
            ]);

            $productsLimit = $request->input('products_limit', 10);
            $latitude = $request->input('latitude');
            $longitude = $request->input('longitude');

            $featuredSection = $this->featuredSectionService->findActiveBySlug($slug);

            if (! $featuredSection) {
                return ApiResponseType::sendJsonResponse(
                    success: false,
                    message: 'labels.featured_section_not_found',
                    data: [],
                    status: 404
                );
            }

            $resource = new FeaturedSectionResource($featuredSection);
            // Add products if requested and add location data if provided
            $additional = ['products_limit' => $productsLimit];

            if ($latitude && $longitude) {
                // keep geo validation consistent using DeliveryZoneService
                $zoneInfo = DeliveryZoneService::getZonesAtPoint($latitude, $longitude);
                if (! $zoneInfo['exists']) {
                    return ApiResponseType::sendJsonResponse(
                        success: false,
                        message: 'labels.delivery_not_available_at_location',
                        data: [],
                        status: 404
                    );
                }
                $additional['latitude'] = $latitude;
                $additional['longitude'] = $longitude;
                $additional['zone_info'] = $zoneInfo;
            }

            $resource->additional($additional);

            return ApiResponseType::sendJsonResponse(
                success: true,
                message: 'labels.featured_section_fetched_successfully',
                data: $resource
            );

        } catch (\Exception $e) {
            return ApiResponseType::sendJsonResponse(
                success: false,
                message: 'labels.something_went_wrong',
                data: [],
                status: 500
            );
        }
    }

    /**
     * Get products for a specific featured section.
     */
    #[QueryParameter('per_page', description: 'Number of products per page.', type: 'int', default: 15, example: 15)]
    #[QueryParameter('page', description: 'Page number for pagination.', type: 'int', default: 1, example: 1)]
    #[QueryParameter('latitude', description: 'User latitude for location-based filtering.', type: 'float', example: '37.7749')]
    #[QueryParameter('longitude', description: 'User longitude for location-based filtering.', type: 'float', example: '-122.4194')]
    #[QueryParameter('sort', description: 'Enter sort filter', type: 'string', example: 'price_asc, price_desc, relevance, avg_rated, best_seller, featured', )]
    #[QueryParameter('categories', description: 'Comma-separated list of category slugs to filter products', type: 'string', example: 'apple,samsung')]
    #[QueryParameter('brands', description: 'Comma-separated list of brand slugs to filter products', type: 'string', example: 'mobile,electronics')]
    #[QueryParameter('attribute_values', description: 'Comma-separated list of global attribute value IDs to filter products', type: 'string', example: '12,34,56')]
    public function products(ValidateProductsRequest $request, string $slug): JsonResponse
    {
        try {
            $request->validated();
            $perPage = (int) $request->input('per_page', 15);
            [$latitude, $longitude] = [$request->input('latitude'), $request->input('longitude')];
            $sort = $request->input('sort');
            $categories = $this->parseCsvList($request->input('categories'));
            $brands = $this->parseCsvList($request->input('brands'));
            $attributeValues = $this->parseAttributeValues($request->input('attribute_values'));

            $featuredSection = $this->featuredSectionService->findActiveBySlug($slug);

            if (! $featuredSection) {
                return ApiResponseType::sendJsonResponse(
                    success: false,
                    message: 'labels.featured_section_not_found',
                    data: [],
                    status: 404
                );
            }

            $serviceResult = $this->featuredSectionService->getProductsForSection($featuredSection, [
                'per_page' => $perPage,
                'sort' => $sort,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'categories' => $categories,
                'brands' => $brands,
                'attribute_values' => $attributeValues,
            ]);

            if (isset($serviceResult['zone_info']['exists']) && $serviceResult['zone_info']['exists'] === false) {
                return ApiResponseType::sendJsonResponse(
                    success: false,
                    message: 'labels.delivery_not_available_at_location',
                    data: [],
                    status: 404
                );
            }

            $products = $serviceResult['products'];
            $categoryIds = $serviceResult['category_ids'];
            $brandIds = $serviceResult['brand_ids'];

            return $this->buildProductsResponse($products, $categoryIds, $brandIds);

        } catch (\Exception $e) {
            return ApiResponseType::sendJsonResponse(
                success: false,
                message: 'labels.something_went_wrong',
                data: [],
            );
        }
    }

    /**
     * Parse a comma separated string to array of trimmed strings.
     * Returns null if input empty or not a string.
     */
    private function parseCsvList($value): ?array
    {
        if (! empty($value) && is_string($value)) {
            $parts = array_values(array_filter(array_map(fn ($v) => trim($v), explode(',', $value)), fn ($v) => $v !== ''));

            return empty($parts) ? null : $parts;
        }

        return null;
    }

    /**
     * Parse CSV of integers into unique positive ints or null.
     */
    private function parseAttributeValues($value): ?array
    {
        if (! empty($value) && is_string($value)) {
            $ids = array_values(array_unique(array_filter(array_map(function ($v) {
                $n = (int) trim($v);

                return $n > 0 ? $n : null;
            }, explode(',', $value)))));

            return empty($ids) ? null : $ids;
        }

        return null;
    }

    /**
     * Transform product paginator using ProductResource and build standard response.
     */
    private function buildProductsResponse($products, array $categoryIds, array $brandIds): JsonResponse
    {
        // Transform using ProductResource
        $products->getCollection()->transform(function ($product) {
            return new ProductResource($product);
        });

        return ApiResponseType::sendJsonResponse(
            success: true,
            message: 'labels.featured_section_products_fetched_successfully',
            data: [
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
                'category_ids' => $categoryIds,
                'brand_ids' => $brandIds,
                'data' => $products->items(),
            ]
        );
    }

    /**
     * Get featured section types.
     * Returns available section types for filtering.
     */
    public function types(): JsonResponse
    {
        try {
            return ApiResponseType::sendJsonResponse(
                success: true,
                message: 'labels.featured_section_types_fetched_successfully',
                data: FeaturedSectionTypeEnum::values()
            );

        } catch (\Exception $e) {
            return ApiResponseType::sendJsonResponse(
                success: false,
                message: 'labels.something_went_wrong',
                data: [],
                status: 500
            );
        }
    }

    /**
     * featured section Search with zone.
     */
    #[QueryParameter('search', description: 'Search term to filter products by name, description, category name, or tags', type: 'string', example: 'smartphone')]
    #[QueryParameter('per_page', description: 'Products Per Page', type: 'int', default: 15, example: 15)]
    #[QueryParameter('zone_ids', description: 'Comma-separated list of zone IDs to filter products', type: 'string', example: '1,2,3')]
    public function search(Request $request): JsonResponse
    {
        return $this->zoneSearch(
            request:        $request,
            fetchPaginator: fn($zoneIds, $search, $perPage) =>
            $this->featuredSectionService->getAvailableFeaturedByZoneIds(
                zoneIds: $zoneIds, search: $search, perPage: $perPage
            ),
            mapItem:        fn($item) => [
                'id'    => $item->id,
                'value' => $item->id,
                'text'  => $item->title,
            ],
            successMessage: 'labels.featured_sections_fetched_successfully',
        );
    }
}
