<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Skip creation if table already exists
        if (Schema::hasTable('product_monthly_sales')) {
            return;
        }

        Schema::create('product_monthly_sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->integer('year');
            $table->integer('month');
            $table->decimal('total_quantity_sold', 12, 2)->default(0);
            $table->decimal('total_sales_amount', 12, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_monthly_sales');
    }
};
