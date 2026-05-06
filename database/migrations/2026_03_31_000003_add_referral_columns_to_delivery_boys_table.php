<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_boys', function (Blueprint $table) {
            // Their own shareable code, auto-generated on registration
            $table->string('referral_code', 32)->nullable()->unique()->after('verification_remark');
            // The code they entered when registering (who invited them)
            $table->string('friends_code', 32)->nullable()->after('referral_code');
        });
    }

    public function down(): void
    {
        Schema::table('delivery_boys', function (Blueprint $table) {
            $table->dropColumn(['referral_code', 'friends_code']);
        });
    }
};
