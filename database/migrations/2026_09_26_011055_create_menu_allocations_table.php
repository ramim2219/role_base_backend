<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_allocations', function (Blueprint $table) {
            $table->id();

            // The menu being assigned
            $table->foreignId('menu_info_id')
                  ->constrained('menu_infos')
                  ->cascadeOnDelete();

            // Scope: assign to a user OR a user type
            // NULL = not applicable for this allocation type
            $table->foreignId('user_info_id')
                  ->nullable()
                  ->default(null)
                  ->constrained('users')
                  ->cascadeOnDelete();

            $table->foreignId('user_type_id')
                  ->nullable()
                  ->default(null)
                  ->constrained('user_types')
                  ->cascadeOnDelete();

            // Display order + status
            $table->integer('priority')->default(1);
            $table->enum('status', ['active', 'inactive'])
                  ->default('active')
                  ->index();

            // Audit
            $table->foreignId('created_by')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();

            $table->timestamps();

            // Prevent duplicate allocations
            $table->unique(
                ['menu_info_id', 'user_info_id', 'user_type_id'],
                'uq_menu_alloc_scope'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_allocations');
    }
};