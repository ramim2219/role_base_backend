<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();
            $table->foreignId('product_type_id')->nullable()->constrained('product_types')->nullOnDelete();

            $table->string('name', 200);
            $table->text('description')->nullable();

            $table->string('base_sku', 100)->nullable();
            $table->string('base_barcode', 100)->nullable();

            $table->string('product_image', 255)->nullable();

            $table->boolean('has_variants')->default(false);
            $table->boolean('track_stock')->default(true);
            $table->boolean('track_serial')->default(false);
            $table->boolean('allow_purchase')->default(true);
            $table->boolean('allow_sale')->default(true);

            $table->tinyInteger('status')->default(1);

            $table->foreignId('created_by')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();

            $table->timestamps();

            $table->unique(['company_id', 'base_sku'], 'uq_product_company_sku');
            $table->index(['company_id', 'status']);
            $table->index('category_id');
            $table->index('brand_id');
            $table->index('product_type_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};