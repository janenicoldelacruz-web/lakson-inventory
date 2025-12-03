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

            // Foreign key to users table (nullable, so some actions can be anonymous)
            $table->foreignId('user_id')
                ->nullable()  // Nullable in case system actions don't involve users
                ->constrained('users')
                ->nullOnDelete();  // Keep logs even if user is deleted

            // Action type (e.g., "created_product", "updated_purchase")
            $table->string('action', 100);

            // The type of entity being logged (e.g., "Product", "Sale", "Purchase")
            $table->string('entity_type')->nullable();  // "Product" or "Sale", for example

            // The ID of the entity being modified (foreign key)
            $table->unsignedBigInteger('entity_id')->nullable();  // The actual entity id (Product id, Order id, etc.)

            // Store the changes (old and new values in JSON format)
            $table->json('changes')->nullable();  // JSON representation of changes

            // Soft deletes (optional, if you want to keep deleted logs)
            $table->softDeletes();

            // Timestamps for created/updated
            $table->timestamps();

            // Index on entity_type and entity_id for better query performance
            $table->index(['entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
