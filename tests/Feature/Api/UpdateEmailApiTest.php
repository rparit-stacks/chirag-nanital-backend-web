<?php

use App\Enums\GuardNameEnum;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    Notification::fake();

    $this->user = User::forceCreate([
        'name'              => 'Email Tester',
        'email'             => 'current.' . uniqid() . '@example.test',
        'mobile'            => '+15550001111',
        'email_verified_at' => now(),
        'access_panel'      => GuardNameEnum::WEB->value,
    ]);
});

it('rejects unauthenticated requests', function () {
    $this->postJson('/api/user/update-email', ['email' => 'new@example.test'])
        ->assertStatus(401);
});

it('requires a valid email', function () {
    Sanctum::actingAs($this->user);

    $this->postJson('/api/user/update-email', ['email' => 'not-an-email'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('email');
});

it('rejects an email already in use by another user', function () {
    User::forceCreate([
        'name'         => 'Other',
        'email'        => 'taken@example.test',
        'access_panel' => GuardNameEnum::WEB->value,
    ]);
    Sanctum::actingAs($this->user);

    $this->postJson('/api/user/update-email', ['email' => 'taken@example.test'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('email');
});

it('short-circuits when the new email matches the current one', function () {
    Sanctum::actingAs($this->user);

    $this->postJson('/api/user/update-email', ['email' => $this->user->email])
        ->assertOk()
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'labels.email_already_matches');

    // No notification should have been dispatched for a no-op.
    Notification::assertNothingSent();
});

it('updates the email, unverifies, and dispatches the verification notification', function () {
    Sanctum::actingAs($this->user);

    $newEmail = 'updated.' . uniqid() . '@example.test';

    $this->postJson('/api/user/update-email', ['email' => $newEmail])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'labels.email_update_verification_sent')
        ->assertJsonPath('data.email', $newEmail)
        ->assertJsonPath('data.email_verified_at', null);

    $fresh = $this->user->fresh();
    expect($fresh->email)->toBe($newEmail);
    expect($fresh->email_verified_at)->toBeNull();

    Notification::assertSentTo($fresh, VerifyEmail::class);
});

it('marks the email verified when the signed link is visited', function () {
    $newEmail = 'verify.flow.' . uniqid() . '@example.test';
    $this->user->forceFill([
        'email'             => $newEmail,
        'email_verified_at' => null,
    ])->save();

    // Build the same signed URL Laravel's VerifyEmail notification would.
    $url = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        [
            'id'   => $this->user->id,
            'hash' => sha1($this->user->email),
        ]
    );

    $this->get($url)->assertOk()->assertSee('Email verified');

    expect($this->user->fresh()->email_verified_at)->not->toBeNull();
});

it('rejects a tampered signed link', function () {
    $this->user->forceFill(['email_verified_at' => null])->save();

    // Valid signature for this id+hash, but we'll swap the hash afterwards.
    $url = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        [
            'id'   => $this->user->id,
            'hash' => sha1($this->user->email),
        ]
    );

    // Drop the signature — Laravel's 'signed' middleware should reject this.
    $this->get(preg_replace('/([?&])signature=[^&]+/', '', $url))
        ->assertStatus(403);
});
