<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->id();

            $table->date('sale_date');

            $table->enum('sale_type', ['walk_in', 'online']);

            $table->string('customer_name')->nullable();
            $table->string('customer_contact')->nullable();

            $table->enum('status', ['pending', 'confirmed', 'completed'])
                ->default('pending');

            $table->decimal('total_amount', 12, 2)->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales');
    }
};
