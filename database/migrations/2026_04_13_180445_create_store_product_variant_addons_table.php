<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('store_product_variant_addons', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('store_id')->constrained('stores')->onDelete('cascade');
            $table->foreignId('product_variant_id')->constrained('product_variants')->onDelete('cascade');
            $table->foreignId('addon_group_id')->constrained('addon_groups')->onDelete('cascade');
            $table->foreignId('addon_item_id')->constrained('addon_items')->onDelete('cascade');

            // Store-specific overrides
            $table->decimal('price')->nullable();
            $table->decimal('cost')->nullable();
            $table->integer('stock')->default(0);
            $table->boolean('is_available')->default(true);

            // Metadata & timestamps
            $table->json('metadata')->nullable();
            $table->softDeletes();
            $table->timestamps();

            // Composite unique index
            $table->unique(['store_id', 'product_variant_id', 'addon_group_id', 'addon_item_id'], 'unique_store_variant_addon');

            $table->index(['store_id', 'product_variant_id']);
            $table->index('is_available');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_product_variant_addons');
    }
};
