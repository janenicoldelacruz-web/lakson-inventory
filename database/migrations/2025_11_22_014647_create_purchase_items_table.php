<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('purchase_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('purchase_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('product_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('batch_id')
                ->nullable()
                ->constrained('product_batches')
                ->nullOnDelete();

            // Display quantity (sacks/pieces)
            $table->decimal('quantity', 12, 2);
            $table->decimal('unit_cost', 12, 2);
            $table->decimal('line_total', 12, 2);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_items');
    }
};
