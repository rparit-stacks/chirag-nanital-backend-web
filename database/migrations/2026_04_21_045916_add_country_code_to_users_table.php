<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phone callback (Firebase OTP) now splits the E.164 number via the
     * in-repo libphonenumber parser and persists the calling code
     * separately. Pre-existing rows keep whatever format they had — this
     * migration is forward-only and nullable so it never breaks legacy data.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('country_code', 10)->nullable()->after('mobile');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('country_code');
        });
    }
};
