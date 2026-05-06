<?php

use App\Enums\Wallet\WalletTypeEnum;
use App\Models\User;
use App\Models\Wallet;
use App\Services\WalletService;
use Database\Seeders\BackfillWalletTypesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Helper: create a user without relying on a UserFactory (this project
 * doesn't ship one).
 */
function makeUser(array $overrides = []): User
{
    static $counter = 0;
    $counter++;

    return User::create(array_merge([
        'name'     => 'Wallet Tester ' . $counter,
        'email'    => 'wallet-tester-' . $counter . '-' . uniqid() . '@example.test',
        'password' => 'secret-password',
    ], $overrides));
}

/**
 * Insert a minimal seller row directly. Avoids the SellerFactory (which
 * transitively reaches for a non-existent UserFactory) and keeps the test
 * independent of the domain factory's required fields.
 */
function makeSellerFor(int $userId): void
{
    DB::table('sellers')->insert([
        'user_id'                   => $userId,
        'address'                   => 'n/a',
        'city'                      => 'n/a',
        'landmark'                  => 'n/a',
        'state'                     => 'n/a',
        'zipcode'                   => 'n/a',
        'country'                   => 'n/a',
        'country_code'              => 'n/a',
        'business_license'          => 'n/a',
        'articles_of_incorporation' => 'n/a',
        'national_identity_card'    => 'n/a',
        'authorized_signature'      => 'n/a',
        'verification_status'       => 'approved',
        'metadata'                  => '{}',
        'visibility_status'         => 'draft',
        'created_at'                => now(),
        'updated_at'                => now(),
    ]);
}

/**
 * Insert a minimal delivery boy row directly.
 */
function makeDeliveryBoyFor(int $userId): void
{
    DB::table('delivery_boys')->insert([
        'user_id'    => $userId,
        'status'     => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

beforeEach(function () {
    $this->walletService = app(WalletService::class);
});

it('creates a separate wallet per (user_id, type) pair via WalletService::getWallet', function () {
    $user = makeUser();

    $customerWallet = $this->walletService->getWallet($user->id, WalletTypeEnum::CUSTOMER)['data'];
    $sellerWallet   = $this->walletService->getWallet($user->id, WalletTypeEnum::SELLER)['data'];
    $riderWallet    = $this->walletService->getWallet($user->id, WalletTypeEnum::DELIVERY_BOY)['data'];

    expect($customerWallet->id)->not->toBe($sellerWallet->id);
    expect($customerWallet->id)->not->toBe($riderWallet->id);
    expect($sellerWallet->id)->not->toBe($riderWallet->id);

    expect($customerWallet->type->value)->toBe(WalletTypeEnum::CUSTOMER->value);
    expect($sellerWallet->type->value)->toBe(WalletTypeEnum::SELLER->value);
    expect($riderWallet->type->value)->toBe(WalletTypeEnum::DELIVERY_BOY->value);

    expect(Wallet::where('user_id', $user->id)->count())->toBe(3);
});

it('defaults to the customer wallet when no type is given', function () {
    $user = makeUser();

    $wallet = $this->walletService->getWallet($user->id)['data'];

    expect($wallet->type->value)->toBe(WalletTypeEnum::CUSTOMER->value);
});

it('addBalance credits only the wallet of the requested type', function () {
    $user = makeUser();

    $this->walletService->addBalance($user->id, ['amount' => 50, 'payment_method' => 'test'], WalletTypeEnum::SELLER);
    $this->walletService->addBalance($user->id, ['amount' => 10, 'payment_method' => 'test'], WalletTypeEnum::CUSTOMER);

    $sellerWallet   = $this->walletService->getWallet($user->id, WalletTypeEnum::SELLER)['data'];
    $customerWallet = $this->walletService->getWallet($user->id, WalletTypeEnum::CUSTOMER)['data'];

    expect((float) $sellerWallet->balance)->toBe(50.0);
    expect((float) $customerWallet->balance)->toBe(10.0);
});

it('deductBalance cannot draw the customer wallet down using seller balance', function () {
    $user = makeUser();

    // Fund only the seller wallet.
    $this->walletService->addBalance($user->id, ['amount' => 100, 'payment_method' => 'test'], WalletTypeEnum::SELLER);

    // Customer-side deduction must not "borrow" from the seller balance:
    // the customer wallet has no funds, so deduct must fail.
    $result = WalletService::deductBalance($user->id, ['amount' => 25], WalletTypeEnum::CUSTOMER);

    expect($result['success'])->toBeFalse();

    // Seller balance is untouched.
    $sellerWallet = $this->walletService->getWallet($user->id, WalletTypeEnum::SELLER)['data'];
    expect((float) $sellerWallet->balance)->toBe(100.0);
});

it('backfill seeder relabels existing wallets for sellers and delivery boys', function () {
    // A plain customer (no seller / no delivery_boy row).
    $plainUser   = makeUser();
    $plainWallet = Wallet::create([
        'user_id'       => $plainUser->id,
        'type'          => WalletTypeEnum::CUSTOMER->value,
        'balance'       => 5,
        'currency_code' => 'USD',
    ]);

    // A user who happens to be a seller — their existing wallet should flip.
    $sellerUser   = makeUser();
    $sellerWallet = Wallet::create([
        'user_id'       => $sellerUser->id,
        'type'          => WalletTypeEnum::CUSTOMER->value, // legacy default before the backfill
        'balance'       => 200,
        'currency_code' => 'USD',
    ]);
    makeSellerFor($sellerUser->id);

    // A user who is a delivery boy only.
    $riderUser   = makeUser();
    $riderWallet = Wallet::create([
        'user_id'       => $riderUser->id,
        'type'          => WalletTypeEnum::CUSTOMER->value,
        'balance'       => 75,
        'currency_code' => 'USD',
    ]);
    makeDeliveryBoyFor($riderUser->id);

    (new BackfillWalletTypesSeeder())->run();

    expect($plainWallet->fresh()->type->value)->toBe(WalletTypeEnum::CUSTOMER->value);
    expect($sellerWallet->fresh()->type->value)->toBe(WalletTypeEnum::SELLER->value);
    expect($riderWallet->fresh()->type->value)->toBe(WalletTypeEnum::DELIVERY_BOY->value);

    // Balances preserved.
    expect((float) $sellerWallet->fresh()->balance)->toBe(200.0);
    expect((float) $riderWallet->fresh()->balance)->toBe(75.0);

    // Seller's customer wallet is NOT auto-created — it's lazy.
    expect(Wallet::where('user_id', $sellerUser->id)->count())->toBe(1);
    expect(Wallet::where('user_id', $riderUser->id)->count())->toBe(1);
});

it('backfill seeder leaves the customer wallet alone when a role wallet already exists', function () {
    // A seller who already has BOTH wallets (e.g. the customer wallet was
    // lazy-created before the backfill got a chance to run).
    $sellerUser = makeUser();
    makeSellerFor($sellerUser->id);

    $preExistingSellerWallet = Wallet::create([
        'user_id'       => $sellerUser->id,
        'type'          => WalletTypeEnum::SELLER->value,
        'balance'       => 999,
        'currency_code' => 'USD',
    ]);

    $customerWallet = Wallet::create([
        'user_id'       => $sellerUser->id,
        'type'          => WalletTypeEnum::CUSTOMER->value,
        'balance'       => 42,
        'currency_code' => 'USD',
    ]);

    // Same for a delivery boy with both wallets.
    $riderUser = makeUser();
    makeDeliveryBoyFor($riderUser->id);

    $preExistingRiderWallet = Wallet::create([
        'user_id'       => $riderUser->id,
        'type'          => WalletTypeEnum::DELIVERY_BOY->value,
        'balance'       => 500,
        'currency_code' => 'USD',
    ]);

    $riderCustomerWallet = Wallet::create([
        'user_id'       => $riderUser->id,
        'type'          => WalletTypeEnum::CUSTOMER->value,
        'balance'       => 7,
        'currency_code' => 'USD',
    ]);

    (new BackfillWalletTypesSeeder())->run();

    // Neither customer wallet should have been flipped.
    expect($customerWallet->fresh()->type->value)->toBe(WalletTypeEnum::CUSTOMER->value);
    expect($riderCustomerWallet->fresh()->type->value)->toBe(WalletTypeEnum::CUSTOMER->value);

    // And the pre-existing role wallets are untouched.
    expect((float) $preExistingSellerWallet->fresh()->balance)->toBe(999.0);
    expect((float) $preExistingRiderWallet->fresh()->balance)->toBe(500.0);

    // Still exactly 2 wallets per user (not 1, not 3).
    expect(Wallet::where('user_id', $sellerUser->id)->count())->toBe(2);
    expect(Wallet::where('user_id', $riderUser->id)->count())->toBe(2);
});

it('UserResource exposes the wallet matching the explicit ->withWalletType()', function () {
    $user = makeUser();
    $walletService = app(WalletService::class);

    // Fund customer and rider wallets with different balances so we can tell them apart.
    $walletService->addBalance($user->id, ['amount' => 10, 'payment_method' => 'test'], WalletTypeEnum::CUSTOMER);
    $walletService->addBalance($user->id, ['amount' => 250, 'payment_method' => 'test'], WalletTypeEnum::DELIVERY_BOY);

    $customerPayload = (new \App\Http\Resources\User\UserResource($user))
        ->withWalletType(WalletTypeEnum::CUSTOMER)
        ->toArray(request());

    $riderPayload = (new \App\Http\Resources\User\UserResource($user))
        ->withWalletType(WalletTypeEnum::DELIVERY_BOY)
        ->toArray(request());

    expect($customerPayload['wallet_type'])->toBe(WalletTypeEnum::CUSTOMER->value);
    expect((float) $customerPayload['wallet_balance'])->toBe(10.0);

    expect($riderPayload['wallet_type'])->toBe(WalletTypeEnum::DELIVERY_BOY->value);
    expect((float) $riderPayload['wallet_balance'])->toBe(250.0);
});

it('(user_id, type) is enforced as unique at the database level', function () {
    $user = makeUser();

    Wallet::create([
        'user_id'       => $user->id,
        'type'          => WalletTypeEnum::SELLER->value,
        'balance'       => 10,
        'currency_code' => 'USD',
    ]);

    expect(fn () => Wallet::create([
        'user_id'       => $user->id,
        'type'          => WalletTypeEnum::SELLER->value,
        'balance'       => 20,
        'currency_code' => 'USD',
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});
