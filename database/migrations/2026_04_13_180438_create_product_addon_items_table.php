<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('addon_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('addon_group_id')->constrained('addon_groups')->onDelete('cascade');
            $table->string('title');
            $table->string('slug')->unique();

            // Pricing
            $table->decimal('price', 10, 2)->default(0);
            $table->decimal('cost', 10, 2)->nullable();

            // Indicator
            $table->enum('indicator', ['veg', 'non_veg'])->nullable();

            // Availability
            $table->boolean('is_available')->default(true);

            // Ordering & visibility
            $table->integer('sort_order')->default(0);
            $table->enum('status', ['active', 'inactive'])->default('active');

            $table->json('metadata')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index('addon_group_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('addon_items');
    }
};
