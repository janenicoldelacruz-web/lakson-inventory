<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brands', function (Blueprint $table) {
            $table->id();

            // Brand belongs to a product category
            $table->foreignId('product_category_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('name');

            $table->timestamps();
            $table->softDeletes();

            // Unique name per category (ignores soft deleted rows)
            $table->unique(['product_category_id', 'name'], 'brands_unique_per_category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brands');
    }
};
