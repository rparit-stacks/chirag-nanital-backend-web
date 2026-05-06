<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referral_earnings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('referral_id');
            $table->unsignedBigInteger('beneficiary_id')->comment('User who receives this bonus (referrer OR referee)');
            $table->enum('beneficiary_type', ['referrer', 'referee'])->comment('Which side of the referral gets this earning');
            $table->unsignedBigInteger('order_id')->nullable();

            // Snapshotted order data
            $table->decimal('order_amount', 12, 2)->comment('Snapshot of order total at time of earning');

            // Snapshotted bonus config (admin settings can change later, these must be immutable)
            $table->enum('bonus_method', ['fixed', 'percentage'])->comment('Snapshot of configured bonus method');
            $table->decimal('bonus_value', 10, 2)->comment('Snapshot of configured bonus value/percentage');
            $table->decimal('max_cap', 10, 2)->nullable()->comment('Snapshot of max cap setting');

            // Calculated result
            $table->decimal('earned_amount', 12, 2)->comment('Actual amount to be credited to wallet');

            // Wallet audit link
            $table->unsignedBigInteger('wallet_transaction_id')->nullable()->comment('FK to wallet_transactions created on settlement');
            
            // Status lifecycle
            $table->enum('status', ['pending', 'success', 'failed'])->default('pending')
            ->comment('pending=awaiting return window; success=wallet credited; failed=wallet not credited');
            
            // Settlement timing (set to MAX return_deadline of order items when created)
            $table->timestamp('settled_at')->nullable()->comment('When wallet was actually credited');

            $table->timestamps();

            $table->foreign('referral_id')->references('id')->on('referrals')->onDelete('cascade');
            $table->foreign('beneficiary_id')->references('id')->on('users');
            $table->foreign('order_id')->references('id')->on('orders');
            $table->foreign('wallet_transaction_id')->references('id')->on('wallet_transactions')->nullOnDelete();

            $table->index(['referral_id', 'order_id']);
            $table->index(['beneficiary_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_earnings');
    }
};
