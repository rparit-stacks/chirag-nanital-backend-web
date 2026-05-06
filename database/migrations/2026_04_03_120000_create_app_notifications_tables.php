<?php

use App\Enums\Notification\NotificationAudienceTypeEnum;
use App\Enums\Notification\NotificationTargetTypeEnum;
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
        Schema::dropIfExists('app_notifications');
        Schema::create('app_notifications', function (Blueprint $table) {
            $table->id();
            $table->enum('audience_type', NotificationAudienceTypeEnum::values());
            $table->string('title')->nullable();
            $table->text('message')->nullable();
            $table->enum('target_type', NotificationTargetTypeEnum::values())->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('audience_type');
            $table->index('target_type');
        });

        Schema::create('app_notification_user_map', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notification_id')->constrained('app_notifications')->onDelete('cascade');
            $table->unsignedBigInteger('user_id');
            $table->enum('user_type', NotificationAudienceTypeEnum::values());
            $table->timestamps();

            $table->index('user_id');
            $table->index('user_type');
            $table->unique(['notification_id', 'user_id', 'user_type'], 'app_notification_user_map_unique');
        });

        Schema::create('app_notification_zone_map', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notification_id')->constrained('app_notifications')->onDelete('cascade');
            $table->foreignId('zone_id')->constrained('delivery_zones')->onDelete('cascade');
            $table->timestamps();

            $table->unique(['notification_id', 'zone_id'], 'app_notification_zone_map_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('app_notification_zone_map');
        Schema::dropIfExists('app_notification_user_map');
        Schema::dropIfExists('app_notifications');
    }
};
