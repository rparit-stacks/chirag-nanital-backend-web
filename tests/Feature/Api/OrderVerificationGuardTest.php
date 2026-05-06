<?php

use App\Enums\GuardNameEnum;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Exercises the verification gate on POST /api/orders/. Both email and
 * mobile must be verified before the request reaches OrderService; we
 * therefore don't need a valid order payload for these assertions — the
 * FormRequest's authorize() rejects the request before validation runs.
 */
beforeEach(function () {
    $this->user = User::forceCreate([
        'name'         => 'Verify Test',
        'email'        => 'verify.test.' . uniqid() . '@example.test',
        'mobile'       => '+15550000000',
        'access_panel' => GuardNameEnum::WEB->value,
    ]);
});

it('blocks order placement when both email and mobile are unverified', function () {
    Sanctum::actingAs($this->user);

    $this->postJson('/api/orders', [])
        ->assertOk()
        ->assertJsonPath('success', false)
        ->assertJsonPath('data.email_verified', false)
        ->assertJsonPath('data.mobile_verified', false);
});

it('blocks order placement when only email is verified', function () {
    $this->user->forceFill(['email_verified_at' => now()])->save();
    Sanctum::actingAs($this->user);

    $this->postJson('/api/orders', [])
        ->assertOk()
        ->assertJsonPath('success', false)
        ->assertJsonPath('data.email_verified', true)
        ->assertJsonPath('data.mobile_verified', false);
});

it('blocks order placement when only mobile is verified', function () {
    $this->user->forceFill(['mobile_verified_at' => now()])->save();
    Sanctum::actingAs($this->user);

    $this->postJson('/api/orders', [])
        ->assertOk()
        ->assertJsonPath('success', false)
        ->assertJsonPath('data.email_verified', false)
        ->assertJsonPath('data.mobile_verified', true);
});

it('lets a fully verified user through to order validation', function () {
    $this->user->forceFill([
        'email_verified_at'  => now(),
        'mobile_verified_at' => now(),
    ])->save();

    Sanctum::actingAs($this->user);

    // Empty payload will now fail validation (422) instead of being blocked
    // by the verification guard — proves the gate let us through.
    $response = $this->postJson('/api/orders', []);

    expect($response->status())->toBe(422);
});

it('reports isFullyVerified correctly at the model level', function () {
    expect($this->user->isFullyVerified())->toBeFalse();

    $this->user->email_verified_at = now();
    expect($this->user->isFullyVerified())->toBeFalse();

    $this->user->mobile_verified_at = now();
    expect($this->user->isFullyVerified())->toBeTrue();
});
