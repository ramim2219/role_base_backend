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

            // Required category + company
            $table->foreignId('category_id')
                  ->constrained('categories')
                  ->cascadeOnDelete();

            $table->foreignId('company_id')
                  ->constrained('companies')
                  ->cascadeOnDelete();

            // Optional brand logo
            $table->string('logo', 255)->nullable();

            $table->tinyInteger('status')->default(1)->comment('1=active, 0=inactive');

            $table->foreignId('created_by')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();

            $table->timestamps();

            // Helpful indexes
            $table->index(['company_id', 'status']);
            $table->index('category_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brands');
    }
};