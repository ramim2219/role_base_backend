<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 100)->unique()->after('email');
            $table->foreignId('company_id')->nullable()->after('password')->constrained('companies')->nullOnDelete();
            $table->foreignId('user_type_id')->nullable()->after('company_id')->constrained('user_types')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->after('user_type_id')->constrained('users')->nullOnDelete();
            $table->tinyInteger('status')->default(1)->after('created_by');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropForeign(['user_type_id']);
            $table->dropForeign(['created_by']);
            $table->dropColumn(['username', 'company_id', 'user_type_id', 'created_by', 'status']);
        });
    }
};