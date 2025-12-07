<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
       Schema::create('product_batches', function (Blueprint $table) {
    $table->id();

    $table->foreignId('product_id')
        ->constrained()
        ->cascadeOnDelete();

    $table->string('batch_code');
    $table->date('expiry_date')->nullable();

    // Whole number quantity
    $table->integer('quantity');

    $table->timestamps();
});

    }

    public function down(): void
    {
        Schema::dropIfExists('product_batches');
    }
};
