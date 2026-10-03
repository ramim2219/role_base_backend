<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();

            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();

            $table->string('sku', 100)->nullable();
            $table->string('barcode', 100)->nullable();

            $table->string('variant_name', 200)->nullable();

            $table->decimal('purchase_price', 12, 2)->default(0);
            $table->decimal('selling_price', 12, 2)->default(0);
            $table->decimal('mrp', 12, 2)->default(0);
            $table->decimal('wholesale_price', 12, 2)->default(0);

            $table->decimal('weight', 10, 3)->nullable();  // optional
            $table->string('image', 255)->nullable();

            $table->boolean('track_stock')->default(true);
            $table->boolean('track_serial')->default(false);

            $table->tinyInteger('status')->default(1);

            $table->timestamps();

            $table->index('product_id');
            $table->index('sku');
            $table->index('barcode');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};