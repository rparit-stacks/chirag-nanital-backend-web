<?php

use App\Enums\Wallet\WalletTypeEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Introduce per-panel wallets.
     *
     * A user can now own up to three wallets (customer / seller / delivery_boy).
     * The real ownership resolver is (user_id, type) — backfill treats every
     * existing row as CUSTOMER; the BackfillWalletTypesSeeder re-assigns
     * seller/rider rows afterwards so their earnings stay intact.
     */
    public function up(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            $table->string('type', 32)
                ->default(WalletTypeEnum::CUSTOMER->value)
                ->after('user_id');
        });

        DB::table('wallets')
            ->whereNull('type')
            ->update(['type' => WalletTypeEnum::CUSTOMER->value]);

        Schema::table('wallets', function (Blueprint $table) {
            $table->unique(['user_id', 'type'], 'wallets_user_id_type_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            $table->dropUnique('wallets_user_id_type_unique');
        });

        Schema::table('wallets', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
