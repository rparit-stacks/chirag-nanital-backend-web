<?php

namespace App\Services;

use App\Types\Api\ApiResponseType;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

/**
 * License domain string must match everywhere (middleware signature check, revalidate form, remote API).
 */
class LicenseValidator
{
    /**
     * Canonical site URL for license binding (no trailing slash). Uses APP_URL; in production
     * upgrades http:// to https:// so Nginx/APP_URL mismatch does not break verification.
     */
    public static function canonicalLicenseDomain(): string
    {
        $root = (string) config('app.url', '');
        $root = str_replace('/public', '', $root);
        $root = rtrim($root, '/');

        if ($root === '') {
            $root = rtrim(request()->getSchemeAndHttpHost(), '/');
        }

        if (app()->environment('production') && str_starts_with($root, 'http://')) {
            $root = 'https://'.substr($root, 7);
        }

        return $root;
    }

    protected Client $http;

    public function __construct(?Client $http = null)
    {
        $this->http = $http ?: new Client([
            'timeout' => 10,
        ]);
    }

    public function validate(string $purchaseCode, string $domainUrl): array
    {
        $endpoint = config('license.endpoint', 'https://validator.infinitietech.com/home/validator');

        try {
            $response = $this->http->get($endpoint, [
                'query' => [
                    'purchase_code' => $purchaseCode,
                    'domain_url' => $domainUrl,
                ],
                'http_errors' => false,
            ]);

            $data = json_decode((string)$response->getBody(), true) ?: [];
        } catch (\Exception $e) {
            Log::error($e);
        }

        return ApiResponseType::toArray(success: ($data['error'] ?? false) == false ? true : false, message: $data['message'] ?? 'Error', data: $data ?? []);
    }

    public static function signature(string $purchaseCode, string $domainUrl, string $token): string
    {
        $key = config('app.key', 'app-key-missing');
        return hash_hmac('sha256', $purchaseCode . '|' . $domainUrl . '|' . $token, $key);
    }
}
