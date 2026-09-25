<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_infos', function (Blueprint $table) {
            $table->id();

            // Menu info
            $table->string('menu', 150);
            $table->string('icon', 100)->nullable()->comment('e.g. fas fa-phone');
            $table->string('menu_url', 255)->nullable();

            // Hierarchy — NULL = top-level, otherwise FK to menu_infos.id
            $table->foreignId('parent_id')
                  ->nullable()
                  ->default(null)
                  ->constrained('menu_infos')
                  ->cascadeOnDelete();

            // Type — Menu or Access (submenu is a Menu with parent_id != NULL)
            $table->enum('type', ['Menu', 'Access'])->default('Menu')->index();

            // Status
            $table->enum('status', ['On', 'Off'])->default('On')->index();

            // Sort order among siblings
            $table->integer('menu_order')->default(1);

            // Audit
            $table->foreignId('created_by')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();

            $table->timestamps();

            // Composite index for tree traversal
            $table->index(['parent_id', 'menu_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_infos');
    }
};