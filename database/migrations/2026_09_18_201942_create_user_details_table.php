<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_details', function (Blueprint $table) {
            $table->id();
            $table->string('image', 255)->nullable();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('full_name', 150);
            $table->string('contact', 20);
            $table->text('present_address');
            $table->text('permanent_address');
            $table->string('father_name', 150);
            $table->string('mother_name', 150);
            $table->date('date_of_birth');
            $table->string('marital_status', 30);
            $table->string('spouse_name', 150)->nullable();
            $table->string('nid_number', 30)->nullable();
            $table->string('gender', 20);
            $table->string('birth_number', 30)->nullable();
            $table->string('religion', 50)->nullable();
            $table->string('nationality', 50);
            $table->string('blood_group', 5)->nullable();
            $table->date('joining_date')->nullable();
            $table->date('resignation_date')->nullable();
            $table->string('emergency_contact_name', 150)->nullable();
            $table->string('emergency_contact_relation', 50)->nullable();
            $table->string('emergency_contact_phone', 20)->nullable();
            $table->text('emergency_contact_address')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_details');
    }
};