<?php

use App\Enums\GuardNameEnum;
use App\Enums\UserLoginTypeEnum;
use App\Models\User;
use App\Services\SocialAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Kreait\Firebase\Auth\UserRecord;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Listeners on UserRegistered / UserLoggedIn dispatch unrelated work
    // (notifications, activity logs). Silence them so the tests stay narrow.
    Event::fake();

    $this->service = app(SocialAuthService::class);
});

/**
 * Build a minimal Kreait UserRecord for Google/Apple-shaped payloads.
 */
function fakeFirebaseUser(array $overrides = []): UserRecord
{
    return UserRecord::fromResponseData(array_merge([
        'localId'       => 'uid-' . uniqid(),
        'createdAt'     => (string) (time() * 1000),
        'emailVerified' => false,
    ], $overrides));
}

it('creates a new user from a Google profile with email verified and mobile/password null', function () {
    $firebaseUser = fakeFirebaseUser([
        'localId'       => 'google-uid-1',
        'email'         => 'new.user@example.test',
        'emailVerified' => true,
        'displayName'   => 'New User',
    ]);

    $result = $this->service->loginOrRegisterFromGoogle($firebaseUser);

    expect($result['is_new'])->toBeTrue();

    $user = $result['user']->refresh();

    expect($user->email)->toBe('new.user@example.test');
    expect($user->firebase_uid)->toBe('google-uid-1');
    expect($user->email_verified_at)->not->toBeNull();
    expect($user->mobile)->toBeNull();
    expect($user->mobile_verified_at)->toBeNull();
    expect($user->password)->toBeNull();
    expect($user->name)->toBe('New User');
    expect($user->logged_in_type)->toBe(UserLoginTypeEnum::GOOGLE);
});

it('logs in an existing user by email and backfills firebase_uid + stamps login type', function () {
    User::forceCreate([
        'name'         => 'Existing',
        'email'        => 'existing@example.test',
        'access_panel' => GuardNameEnum::WEB->value,
    ]);

    $firebaseUser = fakeFirebaseUser([
        'localId'       => 'google-uid-2',
        'email'         => 'existing@example.test',
        'emailVerified' => true,
        'displayName'   => 'Existing',
    ]);

    $result = $this->service->loginOrRegisterFromGoogle($firebaseUser);

    expect($result['is_new'])->toBeFalse();
    $user = $result['user']->refresh();
    expect($user->firebase_uid)->toBe('google-uid-2');
    expect($user->email_verified_at)->not->toBeNull();
    expect($user->logged_in_type)->toBe(UserLoginTypeEnum::GOOGLE);
});

it('creates a new Apple user with email when Apple shares it', function () {
    $firebaseUser = fakeFirebaseUser([
        'localId'       => 'apple-uid-1',
        'email'         => 'apple.user@example.test',
        'emailVerified' => true,
        'displayName'   => 'Apple User',
    ]);

    $result = $this->service->loginOrRegisterFromApple($firebaseUser);

    $user = $result['user']->refresh();
    expect($result['is_new'])->toBeTrue();
    expect($user->email)->toBe('apple.user@example.test');
    expect($user->firebase_uid)->toBe('apple-uid-1');
    expect($user->mobile)->toBeNull();
    expect($user->password)->toBeNull();
    expect($user->logged_in_type)->toBe(UserLoginTypeEnum::APPLE);
});

it('creates a new Apple user with only firebase_uid when email is withheld', function () {
    $firebaseUser = fakeFirebaseUser([
        'localId' => 'apple-uid-no-email',
    ]);

    $result = $this->service->loginOrRegisterFromApple($firebaseUser, claims: []);

    $user = $result['user']->refresh();
    expect($result['is_new'])->toBeTrue();
    expect($user->firebase_uid)->toBe('apple-uid-no-email');
    expect($user->email)->toBeNull();
    expect($user->mobile)->toBeNull();
    expect($user->password)->toBeNull();
    expect($user->email_verified_at)->toBeNull();
    expect($user->mobile_verified_at)->toBeNull();
    expect($user->name)->toBe('Apple User');
    expect($user->logged_in_type)->toBe(UserLoginTypeEnum::APPLE);
});

it('logs in an existing Apple user by firebase_uid even without email', function () {
    $existing = User::forceCreate([
        'name'         => 'Apple Returner',
        'firebase_uid' => 'apple-uid-returning',
        'access_panel' => GuardNameEnum::WEB->value,
    ]);

    $firebaseUser = fakeFirebaseUser([
        'localId' => 'apple-uid-returning',
    ]);

    $result = $this->service->loginOrRegisterFromApple($firebaseUser);

    expect($result['is_new'])->toBeFalse();
    expect($result['user']->id)->toBe($existing->id);
});

it('falls back to claims when UserRecord is missing email or name (Apple)', function () {
    $firebaseUser = fakeFirebaseUser([
        'localId' => 'apple-uid-claims',
    ]);

    $claims = [
        'email'          => 'from.claims@example.test',
        'email_verified' => true,
        'name'           => 'Claims Name',
    ];

    $result = $this->service->loginOrRegisterFromApple($firebaseUser, $claims);

    $user = $result['user']->refresh();
    expect($user->email)->toBe('from.claims@example.test');
    expect($user->name)->toBe('Claims Name');
    expect($user->email_verified_at)->not->toBeNull();
});

it('updates logged_in_type when a user switches provider (Google -> Apple)', function () {
    // Seed a user created previously via Google.
    User::forceCreate([
        'name'           => 'Switcher',
        'email'          => 'switch@example.test',
        'firebase_uid'   => 'google-uid-switch',
        'access_panel'   => GuardNameEnum::WEB->value,
        'logged_in_type' => UserLoginTypeEnum::GOOGLE->value,
    ]);

    // Same email, new Apple UID (Apple shared the email, so we match by email).
    $firebaseUser = fakeFirebaseUser([
        'localId'       => 'apple-uid-switch',
        'email'         => 'switch@example.test',
        'emailVerified' => true,
    ]);

    $result = $this->service->loginOrRegisterFromApple($firebaseUser);

    expect($result['is_new'])->toBeFalse();
    expect($result['user']->fresh()->logged_in_type)->toBe(UserLoginTypeEnum::APPLE);
});

it('stores friends_code on the new user when provided', function () {
    // Seed a referrer whose referral_code the new user will use.
    User::forceCreate([
        'name'          => 'Referrer',
        'email'         => 'ref@example.test',
        'referral_code' => 'REF-ABCDEFGH',
        'access_panel'  => GuardNameEnum::WEB->value,
    ]);

    $firebaseUser = fakeFirebaseUser([
        'localId' => 'google-uid-ref',
        'email'   => 'new.ref.user@example.test',
    ]);

    $result = $this->service->loginOrRegisterFromGoogle(
        $firebaseUser,
        friendsCode: 'REF-ABCDEFGH',
    );

    expect($result['user']->fresh()->friends_code)->toBe('REF-ABCDEFGH');
});
