<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('store_addon_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('store_id')
                ->constrained('stores')
                ->cascadeOnDelete();
            $table->foreignId('addon_item_id')
                ->constrained('addon_items')
                ->cascadeOnDelete();

            // Store-level pricing & inventory (single source of truth for addon stock).
            $table->decimal('price', 12, 2);
            $table->decimal('cost', 12, 2)->nullable();
            $table->integer('stock')->default(0);
            $table->boolean('is_available')->default(true);

            $table->json('metadata')->nullable();
            $table->softDeletes();
            $table->timestamps();

            // One row per (store, addon_item). Soft-deleted rows are ignored by MySQL's
            // unique index on non-null columns, so keep it simple here.
            $table->unique(['store_id', 'addon_item_id'], 'unique_store_addon_item');

            $table->index(['store_id', 'is_available']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_addon_items');
    }
};
