<?php

namespace App\Http\Controllers\Api;

use App\Enums\SettingTypeEnum;
use App\Enums\UpdateTypeEnum;
use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\SystemUpdate;
use App\Services\SettingService;
use App\Types\Api\ApiResponseType;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Settings')]
class SettingApiController extends Controller
{
    use AuthorizesRequests;

    protected SettingService $settingService;

    public function __construct(SettingService $settingService)
    {
        $this->settingService = $settingService;
    }

    public function index(): JsonResponse
    {
        $transformedSettings = $this->settingService->getAllSettings();

        return ApiResponseType::sendJsonResponse(
            success: true,
            message: 'labels.settings_fetched_successfully',
            data: $transformedSettings
        );
    }

    public function show($variable): JsonResponse
    {
        $setting_variable = SettingTypeEnum::values();
        if (!in_array($variable, $setting_variable)) {
            return ApiResponseType::sendJsonResponse(
                success: false,
                message: 'labels.invalid_type',
                data: []
            );
        }

        $transformedSetting = $this->settingService->getSettingByVariable($variable);

        if (!$transformedSetting) {
            return ApiResponseType::sendJsonResponse(
                success: false,
                message: 'labels.setting_not_found',
                data: []
            );
        }

        return ApiResponseType::sendJsonResponse(
            success: true,
            message: 'labels.setting_fetched_successfully',
            data: $transformedSetting
        );
    }

    public function settingVariables(): JsonResponse
    {
        return ApiResponseType::sendJsonResponse(
            success: true,
            message: 'labels.setting_variables_fetched_successfully',
            data: SettingTypeEnum::values()
        );
    }

    public function firebaseConfig(): JsonResponse
    {
        $firebase = $this->settingService->getSettingByVariable(SettingTypeEnum::AUTHENTICATION());
        $notification = $this->settingService->getSettingByVariable(SettingTypeEnum::NOTIFICATION());

        return ApiResponseType::sendJsonResponse(
            success: true,
            message: 'labels.firebase_config_fetched_successfully',
            data: [
                'apiKey' => $firebase->value['fireBaseApiKey'] ?? "",
                'authDomain' => $firebase->value['fireBaseAuthDomain'] ?? "",
                'projectId' => $firebase->value['fireBaseProjectId'] ?? "",
                'storageBucket' => $firebase->value['fireBaseStorageBucket'] ?? "",
                'messagingSenderId' => $firebase->value['fireBaseMessagingSenderId'] ?? "",
                'appId' => $firebase->value['fireBaseAppId'] ?? "",
                'vapidKey' => $notification->value['vapIdKey'] ?? ""
            ]
        );
    }

    public function authConfig(): JsonResponse
    {
        $authSetting = $this->settingService->getSettingByVariable(SettingTypeEnum::AUTHENTICATION());

        if (!$authSetting) {
            return ApiResponseType::sendJsonResponse(
                success: false,
                message: 'labels.setting_not_found',
                data: []
            );
        }

        $config = $authSetting->value ?? [];

        return ApiResponseType::sendJsonResponse(
            success: true,
            message: 'labels.auth_config_fetched_successfully',
            data: [
                'customSms' => (bool)($config['customSms'] ?? false),
                'firebase' => (bool)($config['firebase'] ?? false),
                'googleLogin' => (bool)($config['googleLogin'] ?? false),
                'appleLogin' => (bool)($config['appleLogin'] ?? false),
                'smsGateway' => $config['smsGateway'] ?? '',
            ]
        );
    }

    public function checkVersion(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'current_version' => 'required|string',
                'platform' => 'required|in:android,ios',
                'app' => 'required|in:customer,rider,seller,web',
            ]);

            $platform = $request->input('platform');
            $currentVersion = $request->input('current_version');
            $app = $request->input('app');   // customer, rider, or seller

            $appSettings = $this->settingService->getSettingByVariable(SettingTypeEnum::APP());
            $systemUpdateSettings = $this->settingService->getSettingByVariable(SettingTypeEnum::SYSTEM_UPDATE_SETTINGS());
            $config = $appSettings?->value ?? [];
            $systemUpdateSettings = $systemUpdateSettings?->value ?? [];

            // Get active system update configuration
            $activeVersion = SystemUpdate::active()->first();
            $latestVersion = Setting::getCurrentVersion() ?? '1.0.0';
            $updateType = SettingService::getUpdateType(currentVersion: $currentVersion, latestVersion: $latestVersion, minSupportedVersion: $activeVersion['min_supported_version'] ?? $latestVersion);

            // Determine update URL (you can keep or remove later)
            $updateUrl = match ($app) {
                'rider' => $platform === 'android' ? ($config['riderPlaystoreLink'] ?? '') : ($config['riderAppstoreLink'] ?? ''),
                'seller' => $platform === 'android' ? ($config['sellerPlaystoreLink'] ?? '') : ($config['sellerAppstoreLink'] ?? ''),
                default => $platform === 'android' ? ($config['customerPlaystoreLink'] ?? '') : ($config['customerAppstoreLink'] ?? ''),
            };


            // Determine which message to send based on App + Update Type
            $message = match (true) {
                // Customer App
                $app === 'customer' && $updateType === UpdateTypeEnum::FORCE_UPDATE()
                => $systemUpdateSettings['customerForceUpdateMessage'] ?? 'A new version is available. Please update to continue using the app.',

                $app === 'customer' && $updateType === UpdateTypeEnum::SOFT_UPDATE()
                => $systemUpdateSettings['customerSoftUpdateMessage'] ?? 'A newer version is available. Would you like to update?',

                // Rider App
                $app === 'rider' && $updateType === UpdateTypeEnum::FORCE_UPDATE()
                => $systemUpdateSettings['riderForceUpdateMessage'] ?? 'New version available. Please update to continue accepting orders.',

                $app === 'rider' && $updateType === UpdateTypeEnum::SOFT_UPDATE()
                => $systemUpdateSettings['riderSoftUpdateMessage'] ?? 'New version available for Rider App. Update for better performance.',

                // Seller App
                $app === 'seller' && $updateType === UpdateTypeEnum::FORCE_UPDATE()
                => $systemUpdateSettings['sellerForceUpdateMessage'] ?? 'New version available. Please update to manage your store without issues.',

                $app === 'seller' && $updateType === UpdateTypeEnum::SOFT_UPDATE()
                => $systemUpdateSettings['sellerSoftUpdateMessage'] ?? 'New version of Seller App is ready. Update for latest features.',

                // Customer Web
                $app === 'web' && $updateType === UpdateTypeEnum::FORCE_UPDATE()
                => $systemUpdateSettings['webForceUpdateMessage'] ?? 'A new version of the customer web app is available. Please update to continue using the app.',

                $app === 'web' && $updateType === UpdateTypeEnum::SOFT_UPDATE()
                => $systemUpdateSettings['webSoftUpdateMessage'] ?? 'A newer version of the customer web app is available. Would you like to update now?',

                default => $systemUpdateSettings['webForceUpdateMessage'] ?? '',
            };

            $updateAvailable = version_compare($latestVersion, $currentVersion, '>');

            return ApiResponseType::sendJsonResponse(
                success: true,
                message: 'labels.version_check_successful',
                data: [
                    'update_available' => $updateAvailable,
                    'update_type' => $updateType ?? "",
                    'min_supported_version' => $activeVersion['min_supported_version'] ?? '',
                    'latest_version' => $latestVersion,
                    'message' => $message,
                    'update_url' => $updateUrl,
                ]
            );
        } catch (\Throwable $th) {
            return ApiResponseType::sendJsonResponse(
                success: false,
                message: 'labels.something_went_wrong',
                data: [],
            );
        }
    }
}
