<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stocks', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                  ->constrained('companies')
                  ->cascadeOnDelete();

            $table->foreignId('warehouse_id')
                  ->constrained('warehouses')
                  ->cascadeOnDelete();

            $table->foreignId('product_id')
                  ->constrained('products')
                  ->cascadeOnDelete();

            // Nullable: for products without variants
            $table->foreignId('variant_id')
                  ->nullable()
                  ->constrained('product_variants')
                  ->cascadeOnDelete();

            $table->decimal('quantity', 14, 3)->default(0);
            $table->decimal('reserved_quantity', 14, 3)->default(0);
            $table->decimal('available_quantity', 14, 3)->default(0);

            $table->decimal('reorder_level', 14, 3)->default(0);
            $table->decimal('reorder_quantity', 14, 3)->default(0);

            $table->timestamps();

            // One stock row per (warehouse, product, variant)
            $table->unique(
                ['warehouse_id', 'product_id', 'variant_id'],
                'uq_stock_wh_product_variant'
            );

            $table->index(['company_id', 'warehouse_id']);
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stocks');
    }
};