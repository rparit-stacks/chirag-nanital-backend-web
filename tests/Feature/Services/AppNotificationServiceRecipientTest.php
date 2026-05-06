<?php

use App\Enums\GuardNameEnum;
use App\Enums\Notification\NotificationAudienceTypeEnum;
use App\Models\AppNotification;
use App\Models\AppNotificationUserMap;
use App\Models\DeliveryBoy;
use App\Models\Seller;
use App\Models\User;
use App\Notifications\AdminCustomNotification;
use App\Services\AppNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    Notification::fake();
});

it('customer audience does not reach riders or sellers', function () {
    // A plain customer — access_panel is null, matching real registration flow.
    $customer = User::create([
        'name'     => 'Customer One',
        'email'    => 'customer-' . uniqid() . '@example.test',
        'mobile'   => '9000000001',
        'password' => bcrypt('password'),
    ]);

    // A rider user created the same way DeliveryBoyAuthApiController does — no access_panel.
    $riderUser = User::create([
        'name'     => 'Rider One',
        'email'    => 'rider-' . uniqid() . '@example.test',
        'mobile'   => '9000000002',
        'password' => bcrypt('password'),
    ]);
    DeliveryBoy::factory()->create(['user_id' => $riderUser->id]);

    // A seller user — some legacy seller users also have null access_panel.
    $sellerUser = User::create([
        'name'     => 'Seller One',
        'email'    => 'seller-' . uniqid() . '@example.test',
        'mobile'   => '9000000003',
        'password' => bcrypt('password'),
    ]);
    Seller::factory()->create(['user_id' => $sellerUser->id]);

    $notification = AppNotification::create([
        'audience_type' => NotificationAudienceTypeEnum::CUSTOMER(),
        'title'         => 'Hello customers',
        'message'       => 'This should only reach customers',
    ]);

    app(AppNotificationService::class)->send($notification);

    Notification::assertSentTo($customer, AdminCustomNotification::class);
    Notification::assertNotSentTo($riderUser, AdminCustomNotification::class);
    Notification::assertNotSentTo($sellerUser, AdminCustomNotification::class);
});

it('seller audience with specific users only pushes to those sellers', function () {
    // Targeted seller
    $sellerUser = User::create([
        'name'     => 'Seller Target',
        'email'    => 'seller-target-' . uniqid() . '@example.test',
        'mobile'   => '9100000001',
        'password' => bcrypt('password'),
    ]);
    Seller::factory()->create(['user_id' => $sellerUser->id]);

    // Another seller that must NOT be notified
    $otherSellerUser = User::create([
        'name'     => 'Seller Other',
        'email'    => 'seller-other-' . uniqid() . '@example.test',
        'mobile'   => '9100000002',
        'password' => bcrypt('password'),
    ]);
    Seller::factory()->create(['user_id' => $otherSellerUser->id]);

    $notification = AppNotification::create([
        'audience_type' => NotificationAudienceTypeEnum::SELLER(),
        'title'         => 'Seller-only',
        'message'       => 'Targeted seller push',
    ]);
    // Admin picker returns users.id — mirror that exactly.
    AppNotificationUserMap::create([
        'notification_id' => $notification->id,
        'user_id'         => $sellerUser->id,
        'user_type'       => NotificationAudienceTypeEnum::SELLER()->value,
    ]);

    app(AppNotificationService::class)->send($notification->fresh(['userMaps', 'zoneMaps']));

    Notification::assertSentTo($sellerUser, AdminCustomNotification::class);
    Notification::assertNotSentTo($otherSellerUser, AdminCustomNotification::class);
});

it('rider audience with specific users only pushes to those riders', function () {
    $riderUser = User::create([
        'name'     => 'Rider Target',
        'email'    => 'rider-target-' . uniqid() . '@example.test',
        'mobile'   => '9200000001',
        'password' => bcrypt('password'),
    ]);
    DeliveryBoy::factory()->create(['user_id' => $riderUser->id]);

    $otherRiderUser = User::create([
        'name'     => 'Rider Other',
        'email'    => 'rider-other-' . uniqid() . '@example.test',
        'mobile'   => '9200000002',
        'password' => bcrypt('password'),
    ]);
    DeliveryBoy::factory()->create(['user_id' => $otherRiderUser->id]);

    $notification = AppNotification::create([
        'audience_type' => NotificationAudienceTypeEnum::RIDER(),
        'title'         => 'Rider-only',
        'message'       => 'Targeted rider push',
    ]);
    AppNotificationUserMap::create([
        'notification_id' => $notification->id,
        'user_id'         => $riderUser->id,
        'user_type'       => NotificationAudienceTypeEnum::RIDER()->value,
    ]);

    app(AppNotificationService::class)->send($notification->fresh(['userMaps', 'zoneMaps']));

    Notification::assertSentTo($riderUser, AdminCustomNotification::class);
    Notification::assertNotSentTo($otherRiderUser, AdminCustomNotification::class);
});

it('customer audience still includes users with web access_panel and excludes riders', function () {
    $webCustomer = User::create([
        'name'         => 'Web Customer',
        'email'        => 'web-' . uniqid() . '@example.test',
        'mobile'       => '9000000004',
        'password'     => bcrypt('password'),
        'access_panel' => GuardNameEnum::WEB->value,
    ]);

    $riderUser = User::create([
        'name'     => 'Rider Two',
        'email'    => 'rider2-' . uniqid() . '@example.test',
        'mobile'   => '9000000005',
        'password' => bcrypt('password'),
    ]);
    DeliveryBoy::factory()->create(['user_id' => $riderUser->id]);

    $notification = AppNotification::create([
        'audience_type' => NotificationAudienceTypeEnum::CUSTOMER(),
        'title'         => 'Hello web',
        'message'       => 'Web customers only',
    ]);

    app(AppNotificationService::class)->send($notification);

    Notification::assertSentTo($webCustomer, AdminCustomNotification::class);
    Notification::assertNotSentTo($riderUser, AdminCustomNotification::class);
});
