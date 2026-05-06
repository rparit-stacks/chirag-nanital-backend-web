<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_boy_referrals', function (Blueprint $table) {
            $table->id();

            // DB-A: the one who shared the code
            $table->foreignId('referrer_id')->constrained('delivery_boys')->cascadeOnDelete();

            // DB-B: the new delivery boy who used the code
            // UNIQUE: a DB can only ever be referred once in their lifetime
            $table->foreignId('referred_id')->unique()->constrained('delivery_boys')->cascadeOnDelete();

            // Snapshot of the referral code used — preserves audit even if code is regenerated
            $table->string('referral_code', 32);

            // pending  = DB-B registered, awaiting admin verification
            // rewarded = Admin verified DB-B, bonus paid to both
            // cancelled = Admin rejected DB-B, no bonus paid
            $table->enum('status', ['pending', 'rewarded', 'cancelled'])->default('pending');

            $table->timestamp('rewarded_at')->nullable();
            $table->timestamps();

            $table->index(['referrer_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_boy_referrals');
    }
};
