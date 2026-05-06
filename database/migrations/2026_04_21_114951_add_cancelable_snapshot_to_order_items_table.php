<?php

use App\Enums\Order\OrderItemStatusEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            // Snapshot the product's cancellation policy at order creation so
            // later edits to the product cannot retroactively expose cancel
            // actions on historical orders.
            $table->boolean('is_cancelable')->default(false)->after('returnable_days');
            $table->enum('cancelable_till', [
                OrderItemStatusEnum::PENDING(),
                OrderItemStatusEnum::AWAITING_STORE_RESPONSE(),
                OrderItemStatusEnum::ACCEPTED(),
                OrderItemStatusEnum::PREPARING(),
            ])->nullable()->after('is_cancelable');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['is_cancelable', 'cancelable_till']);
        });
    }
};
