<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();

            // Owning company
            $table->foreignId('company_id')
                  ->constrained('companies')
                  ->cascadeOnDelete();

            // Self-referencing parent (nullable for top-level)
            $table->foreignId('parent_id')
                  ->nullable()
                  ->constrained('categories')
                  ->nullOnDelete();

            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->string('image', 255)->nullable();
            $table->tinyInteger('status')->default(1)->comment('1=active, 0=inactive');

            $table->foreignId('created_by')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();

            $table->timestamps();

            // Helpful indexes
            $table->index(['company_id', 'status']);
            $table->index('parent_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};