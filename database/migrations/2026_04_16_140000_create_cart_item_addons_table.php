<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Mirror of order_item_addons, but scoped to cart_items.
     *
     * Holds the snapshot of the addons attached to a cart line so that the
     * price/availability the user saw when they added the item stays stable
     * until checkout. No per-addon quantity column — the addon is implicitly
     * 1-per-parent, matching the order_item_addons contract.
     */
    public function up(): void
    {
        Schema::create('cart_item_addons', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('cart_item_id')->constrained('cart_items')->cascadeOnDelete();
            $table->foreignId('addon_group_id')->constrained('addon_groups')->cascadeOnDelete();
            $table->foreignId('addon_item_id')->constrained('addon_items')->cascadeOnDelete();

            // Snapshot of store-level pricing at the moment the addon was attached.
            $table->decimal('price', 10, 2);

            $table->json('metadata')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index('cart_item_id');
            $table->index(['addon_group_id', 'addon_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_item_addons');
    }
};
