<?php

use App\Enums\Wallet\WalletTypeEnum;
use App\Models\DeliveryBoy;
use App\Models\DeliveryBoyWithdrawalRequest;
use App\Models\User;
use App\Models\Wallet;
use App\Services\WalletService;
use App\Services\WithdrawalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Minimal user creator - this project doesn't ship a UserFactory.
 */
function makeDeliveryBoyUser(array $overrides = []): User
{
    static $counter = 0;
    $counter++;

    return User::create(array_merge([
        'name' => 'Delivery Boy Tester '.$counter,
        'email' => 'delivery-boy-'.$counter.'-'.uniqid().'@example.test',
        'password' => 'secret-password',
    ], $overrides));
}

/**
 * Minimal verified delivery boy row (mirrors VerifiedDeliveryBoy middleware
 * expectations - "verified" verification_status).
 */
function makeVerifiedDeliveryBoyRow(int $userId): DeliveryBoy
{
    DB::table('delivery_boys')->insert([
        'user_id' => $userId,
        'status' => 'active',
        'verification_status' => 'verified',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return DeliveryBoy::where('user_id', $userId)->firstOrFail();
}

it('releasing a rejected withdrawal unblocks the delivery boy wallet and profile shows zero blocked balance', function () {
    $user = makeDeliveryBoyUser();
    $deliveryBoy = makeVerifiedDeliveryBoyRow($user->id);
    Sanctum::actingAs($user);

    $walletService = app(WalletService::class);
    $withdrawalService = app(WithdrawalService::class);

    $walletService->addBalance(
        $user->id,
        ['amount' => 250, 'payment_method' => 'admin', 'description' => 'Earnings'],
        WalletTypeEnum::DELIVERY_BOY
    );

    $createResult = $withdrawalService->createDeliveryBoyWithdrawalRequest($deliveryBoy->id, [
        'amount' => 100,
        'note' => 'Withdraw test',
    ]);

    expect($createResult['success'])->toBeTrue();

    $wallet = Wallet::query()
        ->where('user_id', $user->id)
        ->where('type', WalletTypeEnum::DELIVERY_BOY->value)
        ->firstOrFail();

    expect((float) $wallet->blocked_balance)->toBe(100.0);

    $admin = makeDeliveryBoyUser(['email' => 'admin-'.uniqid().'@example.test']);

    /** @var DeliveryBoyWithdrawalRequest $request */
    $request = $createResult['data'];

    $processResult = $withdrawalService->processDeliveryBoyWithdrawalRequest($request->id, [
        'status' => 'rejected',
        'remark' => 'Rejected for test',
    ], $admin->id);

    expect($processResult['success'])->toBeTrue();

    $wallet->refresh();
    expect((float) $wallet->blocked_balance)->toBe(0.0);

    $this->getJson('/api/delivery-boy/profile')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.user.wallet_type', WalletTypeEnum::DELIVERY_BOY->value)
        ->assertJsonPath('data.user.wallet_balance', '250.00')
        ->assertJsonPath('data.user.blocked_balance', '0.00')
        ->assertJsonPath('data.user.available_balance', '250.00');
});

it('approving a withdrawal deducts from balance and clears the blocked balance on the delivery boy wallet', function () {
    $user = makeDeliveryBoyUser();
    $deliveryBoy = makeVerifiedDeliveryBoyRow($user->id);

    $walletService = app(WalletService::class);
    $withdrawalService = app(WithdrawalService::class);

    $walletService->addBalance(
        $user->id,
        ['amount' => 250, 'payment_method' => 'admin', 'description' => 'Earnings'],
        WalletTypeEnum::DELIVERY_BOY
    );

    $createResult = $withdrawalService->createDeliveryBoyWithdrawalRequest($deliveryBoy->id, [
        'amount' => 50,
        'note' => 'Withdraw test',
    ]);

    expect($createResult['success'])->toBeTrue();

    $admin = makeDeliveryBoyUser(['email' => 'admin2-'.uniqid().'@example.test']);

    /** @var DeliveryBoyWithdrawalRequest $request */
    $request = $createResult['data'];

    $processResult = $withdrawalService->processDeliveryBoyWithdrawalRequest($request->id, [
        'status' => 'approved',
        'remark' => 'Approved for test',
    ], $admin->id);

    expect($processResult['success'])->toBeTrue();

    $wallet = Wallet::query()
        ->where('user_id', $user->id)
        ->where('type', WalletTypeEnum::DELIVERY_BOY->value)
        ->firstOrFail();

    expect((float) $wallet->balance)->toBe(200.0);
    expect((float) $wallet->blocked_balance)->toBe(0.0);
});
