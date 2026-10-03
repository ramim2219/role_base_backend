<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                  ->constrained('companies')
                  ->cascadeOnDelete();

            $table->string('name', 100);            // Kilogram, Piece, ...
            $table->string('short_name', 20);       // kg, pcs, L, ...
            $table->string('unit_type', 30);        // weight | volume | length | count | area | other
            $table->boolean('allow_decimal')->default(false);

            $table->tinyInteger('status')->default(1)->comment('1=active, 0=inactive');

            $table->foreignId('created_by')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();

            $table->timestamps();

            // Same short name can't repeat inside one company
            $table->unique(['company_id', 'short_name'], 'uq_unit_company_short');
            $table->index(['company_id', 'status']);
            $table->index('unit_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('units');
    }
};