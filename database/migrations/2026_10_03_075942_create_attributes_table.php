<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attributes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                  ->constrained('companies')
                  ->cascadeOnDelete();

            $table->string('name', 100);            // slug-friendly internal key: color, size
            $table->string('display_name', 150);    // shown in UI: Colour, Size
            $table->string('input_type', 30);       // text | number | select | multiselect | color | boolean
            $table->boolean('is_variant_attribute')->default(false);
            $table->tinyInteger('status')->default(1)->comment('1=active, 0=inactive');

            $table->foreignId('created_by')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();

            $table->timestamps();

            $table->unique(['company_id', 'name'], 'uq_attr_company_name');
            $table->index(['company_id', 'status']);
            $table->index('is_variant_attribute');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attributes');
    }
};