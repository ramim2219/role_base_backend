<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
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

            $table->foreignId('variant_id')
                  ->nullable()
                  ->constrained('product_variants')
                  ->cascadeOnDelete();

            $table->string('movement_type', 30);
                  // purchase | sale | sale_return | purchase_return |
                  // transfer_in | transfer_out | adjustment | damage |
                  // expired | opening_stock

            $table->string('reference_type', 50)->nullable(); // e.g. "purchase_order", "sale"
            $table->unsignedBigInteger('reference_id')->nullable();

            $table->decimal('quantity', 14, 3);            // signed: +for in, -for out
            $table->decimal('previous_quantity', 14, 3)->default(0);
            $table->decimal('new_quantity', 14, 3)->default(0);

            $table->text('note')->nullable();

            $table->foreignId('created_by')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();

            $table->timestamps();

            $table->index(['company_id', 'warehouse_id']);
            $table->index(['product_id', 'variant_id']);
            $table->index('movement_type');
            $table->index(['reference_type', 'reference_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};