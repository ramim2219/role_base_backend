<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_details', function (Blueprint $table) {
            $table->string('contact', 20)->nullable()->change();
            $table->text('present_address')->nullable()->change();
            $table->text('permanent_address')->nullable()->change();
            $table->string('father_name', 150)->nullable()->change();
            $table->string('mother_name', 150)->nullable()->change();
            $table->date('date_of_birth')->nullable()->change();
            $table->string('marital_status', 30)->nullable()->change();
            $table->string('gender', 20)->nullable()->change();
            $table->string('nationality', 50)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('user_details', function (Blueprint $table) {
            $table->string('contact', 20)->nullable(false)->change();
            $table->text('present_address')->nullable(false)->change();
            $table->text('permanent_address')->nullable(false)->change();
            $table->string('father_name', 150)->nullable(false)->change();
            $table->string('mother_name', 150)->nullable(false)->change();
            $table->date('date_of_birth')->nullable(false)->change();
            $table->string('marital_status', 30)->nullable(false)->change();
            $table->string('gender', 20)->nullable(false)->change();
            $table->string('nationality', 50)->nullable(false)->change();
        });
    }
};