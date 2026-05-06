<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('delivery_boy_referral_earnings', function (Blueprint $table) {
            $table->id();

            $table->foreignId('referral_id')->constrained('delivery_boy_referrals')->cascadeOnDelete();

            // The DB who received the bonus (either referrer or referee)
            $table->foreignId('beneficiary_id')->constrained('delivery_boys')->cascadeOnDelete();

            // referrer = DB-A who shared the code
            // referee  = DB-B who used the code (new delivery boy)
            $table->enum('beneficiary_type', ['referrer', 'referee']);

            // Snapshot of the setting value at the time of payout
            // Protects against future admin changes to bonus amounts
            $table->decimal('bonus_amount', 10, 2);

            // Cross-reference to the wallet transaction for audit
            $table->unsignedBigInteger('wallet_transaction_id')->nullable();

            // Earnings are always settled immediately — no pending state
            $table->timestamp('settled_at');

            $table->timestamps();

            $table->index(
                ['referral_id', 'beneficiary_type'],
                'dbre_referral_beneficiary_idx'
            );

            $table->index(
                'beneficiary_id',
                'dbre_beneficiary_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_boy_referral_earnings');
    }
};
