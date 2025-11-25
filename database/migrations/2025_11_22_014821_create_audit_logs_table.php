<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('action', 100);          // e.g., "created_product", "updated_purchase"
            $table->string('entity_type')->nullable(); // e.g., "Product", "Purchase", "Sale"
            $table->unsignedBigInteger('entity_id')->nullable();

            $table->json('changes')->nullable();    // JSON of old/new values or any metadata

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();

            $table->softDeletes();
            $table->timestamps();

            // Optional index for faster queries on entity
            $table->index(['entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
