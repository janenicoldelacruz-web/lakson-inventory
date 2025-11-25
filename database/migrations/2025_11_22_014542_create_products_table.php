<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();

            $table->foreignId('product_category_id')
                ->constrained()
                ->cascadeOnDelete();

            // NEW: brand_id (nullable, FK to brands.id, SET NULL on delete)
            $table->foreignId('brand_id')
                ->nullable()
                ->constrained('brands')
                ->nullOnDelete();

            $table->string('sku')->nullable();
            $table->string('name');

            // 1 = Sack, 2 = Piece
            $table->unsignedTinyInteger('base_unit')->default(1);

            $table->decimal('cost_price', 12, 2)->default(0);
            $table->decimal('selling_price', 12, 2)->default(0);

            $table->decimal('current_stock', 12, 2)->default(0);
            $table->integer('reorder_level')->default(30);

            $table->string('status', 20)->default('active');

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['sku']);
            $table->unique(['name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
