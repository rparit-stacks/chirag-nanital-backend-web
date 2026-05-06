<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referrals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('referrer_id')->comment('User who shared the code');
            $table->unsignedBigInteger('referred_id')->unique()->comment('New user who used the code');
            $table->string('referral_code', 32)->comment('Snapshot of the code used at registration');
            $table->enum('status', ['pending', 'active', 'completed', 'cancelled'])
                ->default('pending')
                ->comment('pending=no orders yet, active=some rewarded, completed=all bonuses exhausted, cancelled=invalidated');
            $table->timestamp('rewarded_at')->nullable()->comment('Timestamp of first bonus settlement');
            $table->timestamp('completed_at')->nullable()->comment('Timestamp when all allowed bonuses were exhausted');
            $table->timestamps();

            $table->foreign('referrer_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('referred_id')->references('id')->on('users')->onDelete('cascade');

            $table->index('referrer_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referrals');
    }
};
