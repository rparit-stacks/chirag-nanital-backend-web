<?php

namespace App\Traits;

use App\Types\Api\ApiResponseType;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\Request;
trait ZoneAvailability
{
    /**
     * Shared zone-based search handler.
     *
     * @param Request  $request
     * @param callable $fetchPaginator  fn(array $zoneIds, ?string $search, int $perPage) => LengthAwarePaginator
     * @param callable $mapItem         fn($item) => array
     * @param string   $successMessage
     * @param string   $errorMessage
     */
    protected function zoneSearch(
        Request  $request,
        callable $fetchPaginator,
        callable $mapItem,
        string   $successMessage,
        string   $errorMessage = 'labels.something_went_wrong',
    ): JsonResponse {
        try {
            $validated = $request->validate([
                'zone_ids' => 'nullable',
                'search'   => 'nullable|string',
                'per_page' => 'nullable|integer|min:1|max:50',
            ]);

            $zoneIds = $this->normalizeZoneIds($validated['zone_ids'] ?? null);
            $perPage = $validated['per_page'] ?? 20;

            $paginator = $fetchPaginator($zoneIds, $validated['search'] ?? null, $perPage);

            $data = $paginator->getCollection()->map($mapItem)->values();

            return ApiResponseType::sendJsonResponse(
                success: true,
                message: $successMessage,
                data:    $data,
            );

        } catch (ValidationException $e) {
            return ApiResponseType::sendJsonResponse(
                success: false,
                message: 'labels.validation_error',
                data:    $e->errors(),
            );
        } catch (\Exception $e) {
            return ApiResponseType::sendJsonResponse(
                success: false,
                message: $errorMessage,
                data:    $e->getMessage(),
            );
        }
    }

    /**
     * Normalize zone IDs from CSV string, array, or null → int[]
     */
    private function normalizeZoneIds(mixed $input): array
    {
        if (is_string($input)) {
            $ids = array_map('trim', explode(',', $input));
        } elseif (is_array($input)) {
            $ids = $input;
        } else {
            return [];
        }

        return array_values(array_unique(array_filter(array_map('intval', $ids))));
    }
}
