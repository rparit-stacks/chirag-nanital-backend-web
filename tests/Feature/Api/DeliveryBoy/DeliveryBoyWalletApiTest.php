<?php

use App\Enums\Wallet\WalletTypeEnum;
use App\Models\DeliveryBoy;
use App\Models\User;
use App\Models\Wallet;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Minimal user creator — this project doesn't ship a UserFactory.
 */
function makeRiderUser(array $overrides = []): User
{
    static $counter = 0;
    $counter++;

    return User::create(array_merge([
        'name'     => 'Rider Tester ' . $counter,
        'email'    => 'rider-wallet-' . $counter . '-' . uniqid() . '@example.test',
        'password' => 'secret-password',
    ], $overrides));
}

/**
 * Minimal verified delivery boy row (mirrors VerifiedDeliveryBoy middleware
 * expectations — "verified" verification_status).
 */
function makeVerifiedDeliveryBoy(int $userId): DeliveryBoy
{
    DB::table('delivery_boys')->insert([
        'user_id'             => $userId,
        'status'              => 'active',
        'verification_status' => 'verified',
        'created_at'          => now(),
        'updated_at'          => now(),
    ]);

    return DeliveryBoy::where('user_id', $userId)->first();
}

beforeEach(function () {
    $this->user = makeRiderUser();
    makeVerifiedDeliveryBoy($this->user->id);
    Sanctum::actingAs($this->user);

    // Seed some activity on the rider wallet so list/show has content.
    $this->walletService = app(WalletService::class);
    $this->walletService->addBalance(
        $this->user->id,
        ['amount' => 250, 'payment_method' => 'admin', 'description' => 'Earnings for Order #1'],
        WalletTypeEnum::DELIVERY_BOY
    );
});

it('wallet show returns the DELIVERY_BOY wallet, not the customer wallet', function () {
    // Give the user a customer wallet too, with a distinct balance.
    $this->walletService->addBalance(
        $this->user->id,
        ['amount' => 5, 'payment_method' => 'system', 'description' => 'Welcome bonus'],
        WalletTypeEnum::CUSTOMER
    );

    $this->getJson('/api/delivery-boy/wallet')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.type', WalletTypeEnum::DELIVERY_BOY->value)
        ->assertJsonPath('data.balance', '250.00');
});

it('wallet transactions list only includes DELIVERY_BOY-wallet transactions', function () {
    // Create a parallel customer-wallet credit that must NOT show up.
    $this->walletService->addBalance(
        $this->user->id,
        ['amount' => 99, 'payment_method' => 'system', 'description' => 'Customer recharge'],
        WalletTypeEnum::CUSTOMER
    );

    $response = $this->getJson('/api/delivery-boy/wallet/transactions')
        ->assertOk()
        ->assertJsonPath('success', true);

    $transactions = $response->json('data.data');

    expect($transactions)->toHaveCount(1);
    expect($transactions[0]['description'])->toBe('Earnings for Order #1');
    expect((float) $transactions[0]['amount'])->toBe(250.0);
});

it('wallet single transaction returns 404 when the id belongs to the customer wallet', function () {
    // Create a customer-wallet transaction, then attempt to fetch it via
    // the rider endpoint — it must be invisible because the wallet type
    // doesn't match.
    $customerResult = $this->walletService->addBalance(
        $this->user->id,
        ['amount' => 12, 'payment_method' => 'system', 'description' => 'Customer credit'],
        WalletTypeEnum::CUSTOMER
    );
    $customerTxnId = $customerResult['data']['transaction']->id;

    $this->getJson("/api/delivery-boy/wallet/transactions/{$customerTxnId}")
        ->assertOk()
        ->assertJsonPath('success', false);
});

it('wallet single transaction returns the rider-wallet record', function () {
    $riderTxn = Wallet::query()
        ->where('user_id', $this->user->id)
        ->where('type', WalletTypeEnum::DELIVERY_BOY->value)
        ->first()
        ->getKey();

    // Grab the first (and only) rider transaction id.
    $txnId = \App\Models\WalletTransaction::query()
        ->where('wallet_id', $riderTxn)
        ->value('id');

    $this->getJson("/api/delivery-boy/wallet/transactions/{$txnId}")
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.id', $txnId)
        ->assertJsonPath('data.description', 'Earnings for Order #1');
});

it('wallet endpoints are blocked by the VerifiedDeliveryBoy middleware when the user has no rider row', function () {
    // Fresh user with no delivery_boys entry — the VerifiedDeliveryBoy
    // middleware short-circuits with 403 before the controller runs.
    $plainUser = makeRiderUser();
    Sanctum::actingAs($plainUser);

    $this->getJson('/api/delivery-boy/wallet')
        ->assertStatus(403)
        ->assertJsonPath('success', false);
});
