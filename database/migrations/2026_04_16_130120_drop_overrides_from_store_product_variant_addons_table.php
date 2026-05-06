<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 of the addon-inventory rework.
 *
 * Pricing, cost and stock for an addon item are now tracked at the STORE level
 * in `store_addon_items`. The `store_product_variant_addons` table is demoted
 * to a pure mapping table answering "is this addon item offered on this
 * variant at this store?" — so we drop the per-row overrides.
 *
 * Forward-only: no `down()` restoration because the values are no longer
 * maintained anywhere; rolling back in production would leave inconsistent
 * state between the two tables.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('store_product_variant_addons', function (Blueprint $table) {
            // Drop the single-column index on is_available before dropping the column.
            $table->dropIndex(['is_available']);
        });

        Schema::table('store_product_variant_addons', function (Blueprint $table) {
            $table->dropColumn(['price', 'cost', 'stock', 'is_available']);
        });
    }

    public function down(): void
    {
        // Intentionally forward-only. See class docblock.
    }
};
