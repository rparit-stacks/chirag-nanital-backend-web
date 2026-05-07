<?php

namespace App\Providers;

use App\Services\CurrencyService;
use App\Services\SettingService;
use App\Models\WalletTransaction;
use App\Observers\WalletTransactionObserver;
use App\Models\SellerStatement;
use App\Observers\SellerStatementObserver;
use App\Models\DeliveryBoyAssignment;
use App\Observers\DeliveryBoyAssignmentObserver;
use App\Models\OrderItemReturn;
use App\Observers\OrderItemReturnObserver;
use App\Models\Store;
use App\Models\Order;
use App\Observers\StoreObserver;
use App\Observers\OrderObserver;
use App\Models\SubscriptionTransaction;
use App\Observers\SubscriptionTransactionObserver;
use App\Models\SellerSubscription;
use App\Observers\SellerSubscriptionObserver;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use libphonenumber\PhoneNumberUtil;


class AppServiceProvider extends ServiceProvider
{

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->registerLibphonenumberAutoloader();

        $this->app->singleton(CurrencyService::class, function ($app) {
            return new CurrencyService($app->make(SettingService::class));
        });
    }

    /**
     * Expose the in-repo `libphonenumber\` package (see app/Libs/libphonenumber)
     * without relying on a composer dump-autoload step on customer deploys.
     * Registered here because AppServiceProvider boots before any controller
     * that parses Firebase phone callbacks.
     */
    private function registerLibphonenumberAutoloader(): void
    {
        $base = app_path('Libs/libphonenumber');

        spl_autoload_register(function (string $class) use ($base): void {
            if (! str_starts_with($class, 'libphonenumber\\')) {
                return;
            }

            $relative = substr($class, strlen('libphonenumber\\'));
            $path = $base . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';

            if (is_file($path)) {
                require_once $path;
            }
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Schema::defaultStringLength(191);

        // Load settings with safe guards
        [$systemSettings, $appSettings] = $this->loadSettings();

        // Panel and menus
        $panel = $this->detectPanel();
        $menuSeller = config('menu.seller', []);
        $menuAdmin = config('menu.admin', []);

        // Share data to all views
        $this->shareViewData([
            'systemSettings' => $systemSettings,
            'appSettings' => $appSettings,
            'menuSeller' => $menuSeller,
            'menuAdmin' => $menuAdmin,
            'panel' => $panel,
        ]);

        // API docs security
        $this->configureScramble();

        // Match generated URLs to APP_URL (http vs https). Stops reverse-proxy / X-Forwarded-Proto
        // from upgrading links to https when license is registered on http://
        $this->syncUrlSchemeWithAppUrl();

        // Register model observers safely
        $this->registerObservers();
    }

    /**
     * Load system and app settings from DB (if available), with safe guards.
     *
     * @return array{0: array, 1: array}
     */
    private function loadSettings(): array
    {
        $systemSettings = [];
        $appSettings = [];
        try {
            if (Schema::hasTable('settings')) {
                $settingService = app(SettingService::class);
                $resource = $settingService->getSettingByVariable('system');
                $systemSettings = $resource ? ($resource->toArray(request())['value'] ?? []) : [];

                try {
                    $appResource = $settingService->getSettingByVariable('app');
                    $appSettings = $appResource ? ($appResource->toArray(request())['value'] ?? []) : [];
                } catch (\Throwable $e) {
                    Log::warning($e->getMessage());
                }
            }
        } catch (\Throwable $e) {
            Log::warning($e->getMessage());
        }

        return [$systemSettings, $appSettings];
    }

    /**
     * Detect current panel (admin or seller).
     */
    private function detectPanel(): string
    {
        return (request()->is('seller/*') || request()->routeIs('seller.*')) ? 'seller' : 'admin';
    }

    /**
     * Share base data and user to views.
     */
    private function shareViewData(array $data): void
    {
        // Include current authenticated user if available
        if (Auth::check()) {
            $data['user'] = Auth::user();
        }

        view()->share($data);

        // Ensure user is always available via composer as well
        View::composer('*', function ($view) {
            if (Auth::check()) {
                $view->with('user', Auth::user());
            }
        });
    }

    /**
     * Configure Scramble (OpenAPI) security scheme.
     */
    private function configureScramble(): void
    {
        Scramble::configure()
            ->withDocumentTransformers(function (OpenApi $openApi) {
                $openApi->secure(SecurityScheme::http('bearer'));
            });
    }

    /**
     * Align url()/asset() scheme with APP_URL so http:// licenses and plain HTTP deploys work.
     * When behind Nginx+SSL, X-Forwarded-Proto can otherwise force https in generated URLs.
     */
    private function syncUrlSchemeWithAppUrl(): void
    {
        $url = (string) config('app.url', '');

        if (str_starts_with($url, 'https://')) {
            URL::forceScheme('https');
        } elseif (str_starts_with($url, 'http://')) {
            URL::forceScheme('http');
        }
    }

    /**
     * Register all model observers with safe guards.
     */
    private function registerObservers(): void
    {
        $pairs = [
            WalletTransaction::class => WalletTransactionObserver::class,
            SellerStatement::class => SellerStatementObserver::class,
            DeliveryBoyAssignment::class => DeliveryBoyAssignmentObserver::class,
            OrderItemReturn::class => OrderItemReturnObserver::class,
            Store::class => StoreObserver::class,
            Order::class => OrderObserver::class,
            SubscriptionTransaction::class => SubscriptionTransactionObserver::class,
            SellerSubscription::class => SellerSubscriptionObserver::class,
        ];

        foreach ($pairs as $model => $observer) {
            try {
                /** @var class-string $model */
                $model::observe($observer);
            } catch (\Throwable $e) {
                Log::warning('Failed to register '.class_basename($observer).': ' . $e->getMessage());
            }
        }
    }
}
