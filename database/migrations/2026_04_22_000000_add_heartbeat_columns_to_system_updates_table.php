<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_updates', function (Blueprint $table) {
            $table->string('step', 50)->nullable()->after('status');
            $table->unsignedTinyInteger('progress')->default(0)->after('step');
            $table->timestamp('heartbeat_at')->nullable()->after('progress');
            $table->index('heartbeat_at');
        });
    }

    public function down(): void
    {
        Schema::table('system_updates', function (Blueprint $table) {
            $table->dropIndex(['heartbeat_at']);
            $table->dropColumn(['step', 'progress', 'heartbeat_at']);
        });
    }
};
