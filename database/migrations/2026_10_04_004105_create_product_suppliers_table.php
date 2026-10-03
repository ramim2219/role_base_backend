<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_suppliers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('product_id')
                  ->constrained('products')
                  ->cascadeOnDelete();

            // Nullable: applies to the product itself when there are no variants
            $table->foreignId('variant_id')
                  ->nullable()
                  ->constrained('product_variants')
                  ->cascadeOnDelete();

            $table->foreignId('supplier_id')
                  ->constrained('suppliers')
                  ->cascadeOnDelete();

            $table->string('supplier_sku', 100)->nullable();
            $table->decimal('purchase_price', 12, 2)->default(0);
            $table->integer('minimum_order_qty')->default(1);

            $table->boolean('is_preferred')->default(false);
            $table->tinyInteger('status')->default(1);

            $table->timestamps();

            // One supplier can be linked to a product/variant only once
            $table->unique(
                ['product_id', 'variant_id', 'supplier_id'],
                'uq_product_variant_supplier'
            );

            $table->index('supplier_id');
            $table->index(['product_id', 'is_preferred']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_suppliers');
    }
};