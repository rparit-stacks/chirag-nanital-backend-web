<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Social signups (Google/Apple) may not carry a mobile or password,
            // and Apple may not share an email — allow all three to be null.
            $table->string('email')->nullable()->change();
            $table->string('mobile', 20)->nullable()->change();
            $table->string('password')->nullable()->change();

            // Stable identifier for Firebase-backed signins (Apple without
            // email relies on this). Sparse unique: NULLs are allowed.
            $table->string('firebase_uid')->nullable()->unique()->after('id');

            // Mobile verification timestamp — mirrors email_verified_at.
            $table->timestamp('mobile_verified_at')->nullable()->after('email_verified_at');

            // Which authentication provider the user most recently used.
            // PLATFORM = native form; GOOGLE / APPLE = Firebase-backed.
            $table->enum('logged_in_type', ['google', 'apple', 'platform'])
                ->nullable()
                ->after('mobile_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['logged_in_type', 'mobile_verified_at']);

            $table->dropUnique(['firebase_uid']);
            $table->dropColumn('firebase_uid');

            // Revert nullability. Note: if rows with NULL values exist this
            // will fail — clean those up before rolling back.
            $table->string('password')->nullable(false)->change();
            $table->string('mobile', 20)->nullable(false)->change();
            $table->string('email')->nullable(false)->change();
        });
    }
};
