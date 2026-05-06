<?php

namespace Database\Seeders;

use App\Enums\Wallet\WalletTypeEnum;
use App\Models\DeliveryBoy;
use App\Models\Seller;
use App\Models\Wallet;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * One-shot backfill for the wallets.type column.
 *
 * Before the polymorphic-wallet change every user had exactly one wallet row.
 * Sellers and delivery boys accumulated settlements in that same row, which
 * also doubled as their customer wallet when they opened the customer app —
 * meaning they could accidentally spend payout balance on their own orders.
 *
 * This seeder re-labels the existing row based on the user's primary role:
 *   - user owns a seller row        → wallet.type = seller
 *   - user owns a delivery_boy row  → wallet.type = delivery_boy
 *   - otherwise                     → wallet.type = customer
 *
 * The customer wallet for a seller/rider is intentionally NOT created here —
 * it's created lazily the first time that user hits the customer-panel wallet
 * endpoint, starting at zero balance. This is what the user asked for.
 *
 * Idempotent and safe against partial state: re-running only touches rows
 * that still look like the legacy default (type = customer), AND only for
 * users who don't already own a wallet of the target role type. This
 * prevents the seeder from clobbering a customer wallet that was lazy-created
 * for a seller/rider who already has a genuine role wallet.
 */
class BackfillWalletTypesSeeder extends Seeder
{
    public function run(): void
    {
        $sellerUsers = Seller::query()->pluck('user_id')->unique()->values();
        $riderUsers  = DeliveryBoy::query()->pluck('user_id')->unique()->values();

        $usersWithSellerWallet = Wallet::query()
            ->where('type', WalletTypeEnum::SELLER->value)
            ->pluck('user_id')
            ->unique();

        $usersWithRiderWallet = Wallet::query()
            ->where('type', WalletTypeEnum::DELIVERY_BOY->value)
            ->pluck('user_id')
            ->unique();

        $sellersToFlip = $sellerUsers->diff($usersWithSellerWallet)->values();
        $ridersToFlip  = $riderUsers->diff($sellerUsers)->diff($usersWithRiderWallet)->values();

        $sellerUpdated = 0;
        $riderUpdated  = 0;
        $sellerSkipped = $sellerUsers->count() - $sellersToFlip->count();
        $riderSkipped  = $riderUsers->diff($sellerUsers)->count() - $ridersToFlip->count();

        DB::transaction(function () use ($sellersToFlip, $ridersToFlip, &$sellerUpdated, &$riderUpdated) {
            if ($sellersToFlip->isNotEmpty()) {
                $sellerUpdated = Wallet::query()
                    ->whereIn('user_id', $sellersToFlip)
                    ->where('type', WalletTypeEnum::CUSTOMER->value)
                    ->update(['type' => WalletTypeEnum::SELLER->value]);
            }

            // Rows for users who are delivery boys but NOT sellers → flip to DELIVERY_BOY.
            if ($ridersToFlip->isNotEmpty()) {
                $riderUpdated = Wallet::query()
                    ->whereIn('user_id', $ridersToFlip)
                    ->where('type', WalletTypeEnum::CUSTOMER->value)
                    ->update(['type' => WalletTypeEnum::DELIVERY_BOY->value]);
            }
        });

        $message = sprintf(
            'BackfillWalletTypesSeeder: re-labeled %d seller wallet(s) and %d delivery-boy wallet(s). '
            . 'Skipped %d seller user(s) and %d rider user(s) who already had a role-type wallet. '
            . 'Remaining rows stay as customer wallets.',
            $sellerUpdated,
            $riderUpdated,
            $sellerSkipped,
            $riderSkipped
        );

        $this->command?->info($message);
        Log::info($message);
    }
}
