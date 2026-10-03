<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_attributes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('attribute_id')->constrained('attributes')->cascadeOnDelete();

            $table->boolean('is_required')->default(true);

            $table->timestamps();

            // One product can have the same attribute only once
            $table->unique(['product_id', 'attribute_id'], 'uq_product_attr');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_attributes');
    }
};