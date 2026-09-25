<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name', 200);
            $table->string('logo', 255)->nullable();
            $table->foreignId('company_type_id')->constrained('company_types')->restrictOnDelete();
            $table->string('slug', 200)->unique();
            $table->tinyInteger('status')->default(1)->comment('1=active, 0=inactive');
            $table->text('address');
            $table->string('contact', 20);
            $table->string('email', 150)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};