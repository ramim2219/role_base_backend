<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('barcodes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();

            // Nullable: some barcodes belong only to the parent product
            $table->foreignId('variant_id')
                  ->nullable()
                  ->constrained('product_variants')
                  ->cascadeOnDelete();

            $table->string('barcode', 150);
            $table->string('barcode_type', 30)->default('internal');
                  // internal | ean | upc | supplier | other

            $table->boolean('is_primary')->default(false);
            $table->tinyInteger('status')->default(1);

            $table->timestamps();

            $table->unique(['company_id', 'barcode'], 'uq_barcode_company');
            $table->index('product_id');
            $table->index('variant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('barcodes');
    }
};