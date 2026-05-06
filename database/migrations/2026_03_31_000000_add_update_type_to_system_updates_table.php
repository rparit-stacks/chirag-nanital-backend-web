<?php

use App\Enums\UpdateTypeEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('system_updates', function (Blueprint $table) {
            $table->string('min_supported_version')->nullable()->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('system_updates', function (Blueprint $table) {
            $table->dropColumn('min_supported_version');
        });
    }
};
