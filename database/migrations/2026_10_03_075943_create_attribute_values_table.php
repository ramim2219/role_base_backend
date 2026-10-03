<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attribute_values', function (Blueprint $table) {
            $table->id();

            $table->foreignId('attribute_id')
                  ->constrained('attributes')
                  ->cascadeOnDelete();

            $table->string('value', 100);           // internal value: black, m, 64gb
            $table->string('display_name', 150);    // shown to user: Black, M, 64 GB
            $table->integer('sort_order')->default(0);
            $table->tinyInteger('status')->default(1)->comment('1=active, 0=inactive');

            $table->timestamps();

            $table->unique(['attribute_id', 'value'], 'uq_attr_value');
            $table->index(['attribute_id', 'status']);
            $table->index('sort_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attribute_values');
    }
};