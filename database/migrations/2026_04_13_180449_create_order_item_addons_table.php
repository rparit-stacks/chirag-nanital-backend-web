<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('order_item_addons', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('order_item_id')->constrained('order_items')->onDelete('cascade');
            $table->foreignId('addon_group_id')->constrained('addon_groups')->onDelete('cascade');
            $table->foreignId('addon_item_id')->constrained('addon_items')->onDelete('cascade');

            // Snapshot of pricing at order time
            $table->decimal('price', 10, 2);

            $table->json('metadata')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index('order_item_id');
            $table->index(['addon_group_id', 'addon_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_item_addons');
    }
};
