<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Add `addon_signature` to `cart_items` for O(1) line-matching when an
     * incoming addToCart call has the same (cart, product, variant, store)
     * tuple as an existing line but a different set of addons. Stored as a
     * hex SHA1 of the sorted addon_item_ids (40 chars), nullable for lines
     * with no addons.
     *
     * Indexed (not unique — two lines with no addons would otherwise clash)
     * alongside the scope columns that gate the lookup.
     */
    public function up(): void
    {
        Schema::table('cart_items', function (Blueprint $table) {
            $table->char('addon_signature', 40)->nullable()->after('quantity');
            $table->index(['cart_id', 'product_variant_id', 'store_id', 'addon_signature'], 'cart_items_addon_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::table('cart_items', function (Blueprint $table) {
            $table->dropIndex('cart_items_addon_lookup_idx');
            $table->dropColumn('addon_signature');
        });
    }
};
