<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('addon_groups', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('seller_id')->nullable()->constrained('sellers')->cascadeOnDelete();
            $table->string('title');
            $table->string('slug')->unique();

            // Selection configuration
            $table->enum('selection_type', ['single', 'multiple'])->default('single');
            $table->boolean('is_required')->default(false);

            // Ordering & visibility
            $table->integer('sort_order')->default(0);
            $table->enum('status', ['active', 'inactive'])->default('active');

            $table->json('metadata')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index('status');
            $table->index('seller_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('addon_groups');
    }
};
