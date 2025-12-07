<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sale_items', function (Blueprint $table) {
    $table->id();

    $table->foreignId('sale_id')
        ->constrained()
        ->cascadeOnDelete();

    $table->foreignId('product_id')
        ->constrained()
        ->cascadeOnDelete();

    $table->foreignId('batch_id')
        ->nullable()
        ->constrained('product_batches')
        ->nullOnDelete();

    // Whole-number quantity
    $table->integer('quantity');

    $table->decimal('unit_price', 12, 2);
    $table->decimal('line_total', 12, 2);

    // for COGS / profit
    $table->decimal('unit_cost_at_sale', 12, 2)->default(0);
    $table->decimal('line_cost', 12, 2)->default(0);

    $table->timestamps();
});

    }

    public function down(): void
    {
        Schema::dropIfExists('sale_items');
    }
};
