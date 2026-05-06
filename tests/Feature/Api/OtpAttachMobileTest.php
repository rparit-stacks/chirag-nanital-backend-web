<?php

use App\Enums\GuardNameEnum;
use App\Models\User;
use App\Services\OtpService;
use App\Services\SettingService;
use App\Services\SmsService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Covers POST /api/auth/verify-otp — both the "authed user attaches +
 * verifies mobile" branch and the anon login/register fallthrough.
 *
 * OtpService::sanitizeMobile truncates inputs >10 digits to the last 10,
 * so every mobile value used below is already 10 digits for clarity.
 */
beforeEach(function () {
    $this->app->bind(OtpService::class, function ($app) {
        return new class(
            $app->make(SmsService::class),
            $app->make(SettingService::class),
            $app->make(WalletService::class),
        ) extends OtpService {
            public function verifyOtp(string $mobile, string $otpCode): array
            {
                return [
                    'success' => true,
                    'message' => 'labels.verified_successfully',
                ];
            }
        };
    });
});

it('attaches mobile to authed social user (no mobile previously set)', function () {
    $user = User::forceCreate([
        'name'               => 'Google Signup',
        'email'              => 'google@example.test',
        'email_verified_at'  => now(),
        'mobile'             => null,
        'mobile_verified_at' => null,
        'access_panel'       => GuardNameEnum::WEB->value,
    ]);
    Sanctum::actingAs($user);

    $this->postJson('/api/auth/verify-otp', [
        'mobile' => '5551234567',
        'otp'    => '123456',
    ])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'labels.mobile_verified_successfully')
        ->assertJsonPath('data.mobile', '5551234567');

    $fresh = $user->fresh();
    expect($fresh->mobile)->toBe('5551234567');
    expect($fresh->mobile_verified_at)->not->toBeNull();
});

it('strips a leading + from the submitted mobile before storage', function () {
    $user = User::forceCreate([
        'name'         => 'Plus Test',
        'email'        => 'plus@example.test',
        'access_panel' => GuardNameEnum::WEB->value,
    ]);
    Sanctum::actingAs($user);

    $this->postJson('/api/auth/verify-otp', [
        'mobile' => '+5552223333',
        'otp'    => '123456',
    ])
        ->assertOk()
        ->assertJsonPath('data.mobile', '5552223333');
});

it('overwrites a stale mobile when authed user verifies a new one', function () {
    $user = User::forceCreate([
        'name'         => 'Has Old Mobile',
        'email'        => 'old@example.test',
        'mobile'       => '1000000000',
        'access_panel' => GuardNameEnum::WEB->value,
    ]);
    Sanctum::actingAs($user);

    $this->postJson('/api/auth/verify-otp', [
        'mobile' => '5559998888',
        'otp'    => '654321',
    ])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.mobile', '5559998888');

    expect($user->fresh()->mobile)->toBe('5559998888');
});

it('rejects attachment when another user already owns that mobile', function () {
    User::forceCreate([
        'name'         => 'Owner',
        'email'        => 'owner@example.test',
        'mobile'       => '5551111111',
        'access_panel' => GuardNameEnum::WEB->value,
    ]);

    $caller = User::forceCreate([
        'name'         => 'Caller',
        'email'        => 'caller@example.test',
        'access_panel' => GuardNameEnum::WEB->value,
    ]);
    Sanctum::actingAs($caller);

    $this->postJson('/api/auth/verify-otp', [
        'mobile' => '5551111111',
        'otp'    => '123456',
    ])
        ->assertOk()
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'labels.mobile_already_in_use_by_another_user');

    expect($caller->fresh()->mobile)->toBeNull();
});

it('updates the name alongside mobile when name is supplied', function () {
    $user = User::forceCreate([
        'name'         => 'Old Name',
        'email'        => 'named@example.test',
        'access_panel' => GuardNameEnum::WEB->value,
    ]);
    Sanctum::actingAs($user);

    $this->postJson('/api/auth/verify-otp', [
        'mobile' => '5552223333',
        'otp'    => '123456',
        'name'   => 'New Name',
    ])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.name', 'New Name');

    expect($user->fresh()->name)->toBe('New Name');
});

it('applies a friends_code to a user who has none yet', function () {
    User::forceCreate([
        'name'          => 'Referrer',
        'email'         => 'ref@example.test',
        'referral_code' => 'REF-ABCDEFGH',
        'access_panel'  => GuardNameEnum::WEB->value,
    ]);

    $caller = User::forceCreate([
        'name'         => 'New Signup',
        'email'        => 'new@example.test',
        'access_panel' => GuardNameEnum::WEB->value,
    ]);
    Sanctum::actingAs($caller);

    $this->postJson('/api/auth/verify-otp', [
        'mobile'       => '5557778888',
        'otp'          => '123456',
        'friends_code' => 'REF-ABCDEFGH',
    ])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.friends_code', 'REF-ABCDEFGH');

    expect($caller->fresh()->friends_code)->toBe('REF-ABCDEFGH');
});

it('rejects the self-referral case', function () {
    $caller = User::forceCreate([
        'name'          => 'Self Refer',
        'email'         => 'self@example.test',
        'referral_code' => 'REF-SELF0000',
        'access_panel'  => GuardNameEnum::WEB->value,
    ]);
    Sanctum::actingAs($caller);

    $this->postJson('/api/auth/verify-otp', [
        'mobile'       => '5550000999',
        'otp'          => '123456',
        'friends_code' => 'REF-SELF0000',
    ])
        ->assertOk()
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'labels.cannot_use_own_referral_code');

    $fresh = $caller->fresh();
    expect($fresh->mobile)->toBeNull();
    expect($fresh->friends_code)->toBeNull();
});

it('rejects a second, different friends_code once one has been applied', function () {
    User::forceCreate([
        'name'          => 'First Referrer',
        'email'         => 'first@example.test',
        'referral_code' => 'REF-FIRST000',
        'access_panel'  => GuardNameEnum::WEB->value,
    ]);
    User::forceCreate([
        'name'          => 'Second Referrer',
        'email'         => 'second@example.test',
        'referral_code' => 'REF-SECOND00',
        'access_panel'  => GuardNameEnum::WEB->value,
    ]);

    $caller = User::forceCreate([
        'name'         => 'Caller',
        'email'        => 'caller.second@example.test',
        'friends_code' => 'REF-FIRST000',
        'access_panel' => GuardNameEnum::WEB->value,
    ]);
    Sanctum::actingAs($caller);

    $this->postJson('/api/auth/verify-otp', [
        'mobile'       => '5554445555',
        'otp'          => '123456',
        'friends_code' => 'REF-SECOND00',
    ])
        ->assertOk()
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'labels.referral_code_already_applied');

    expect($caller->fresh()->friends_code)->toBe('REF-FIRST000');
});

it('is idempotent when the same friends_code is resubmitted', function () {
    User::forceCreate([
        'name'          => 'Same Referrer',
        'email'         => 'same@example.test',
        'referral_code' => 'REF-SAME0000',
        'access_panel'  => GuardNameEnum::WEB->value,
    ]);

    $caller = User::forceCreate([
        'name'         => 'Idempotent',
        'email'        => 'idem@example.test',
        'friends_code' => 'REF-SAME0000',
        'access_panel' => GuardNameEnum::WEB->value,
    ]);
    Sanctum::actingAs($caller);

    $this->postJson('/api/auth/verify-otp', [
        'mobile'       => '5556667777',
        'otp'          => '123456',
        'friends_code' => 'REF-SAME0000',
    ])
        ->assertOk()
        ->assertJsonPath('success', true);

    expect($caller->fresh()->mobile)->toBe('5556667777');
});

it('rejects a friends_code that does not correspond to any user', function () {
    $caller = User::forceCreate([
        'name'         => 'Bad Refer',
        'email'        => 'bad@example.test',
        'access_panel' => GuardNameEnum::WEB->value,
    ]);
    Sanctum::actingAs($caller);

    $this->postJson('/api/auth/verify-otp', [
        'mobile'       => '5551112222',
        'otp'          => '123456',
        'friends_code' => 'REF-NOTREAL0',
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('friends_code');
});

it('rejects missing mobile with a 422', function () {
    $user = User::forceCreate([
        'name'         => 'Invalid',
        'email'        => 'invalid@example.test',
        'access_panel' => GuardNameEnum::WEB->value,
    ]);
    Sanctum::actingAs($user);

    $this->postJson('/api/auth/verify-otp', ['otp' => '123456'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('mobile');
});

it('falls through to the anon login flow when no bearer is sent and the mobile is known', function () {
    $existing = User::forceCreate([
        'name'         => 'Existing',
        'email'        => 'existing@example.test',
        'mobile'       => '5550001111',
        'access_panel' => GuardNameEnum::WEB->value,
    ]);

    $response = $this->postJson('/api/auth/verify-otp', [
        'mobile' => '5550001111',
        'otp'    => '123456',
    ])->assertOk();

    $response->assertJsonPath('success', true);
    expect($response->json('access_token'))->not->toBeEmpty();
    expect($response->json('data.id'))->toBe($existing->id);
});

it('returns the new_user hint when no bearer is sent and the mobile is unknown', function () {
    $this->postJson('/api/auth/verify-otp', [
        'mobile' => '5558889999',
        'otp'    => '123456',
    ])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.new_user', true)
        ->assertJsonPath('data.mobile', '5558889999')
        ->assertJsonPath('data.otp_verified', false);
});
