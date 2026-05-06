<?php

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    Notification::fake();
});

it('dispatches the email verification notification on successful registration', function () {
    $payload = [
        'name'                  => 'Verify Me',
        'email'                 => 'verify.' . uniqid() . '@example.test',
        'mobile'                => '9998887777',
        'password'              => 'secret-password',
        'password_confirmation' => 'secret-password',
    ];

    $response = $this->postJson('/api/register', $payload);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'labels.registration_successful_verification_sent');

    $user = User::where('email', $payload['email'])->firstOrFail();

    expect($user->email_verified_at)->toBeNull();
    // No idToken was supplied, so the mobile must remain unverified.
    expect($user->mobile_verified_at)->toBeNull();

    Notification::assertSentTo($user, VerifyEmail::class);
});

it('does not send a verification email when registration fails validation', function () {
    $this->postJson('/api/register', [
        'name'  => 'Broken',
        'email' => 'not-an-email',
    ])->assertStatus(200) // trait returns 200 with success=false on validation error
        ->assertJsonPath('success', false);

    Notification::assertNothingSent();
});
