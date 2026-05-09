<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('document_categories', function (Blueprint $table) {
            $table->id();

            /*
            |--------------------------------------------------------------------------
            | Parent Category
            |--------------------------------------------------------------------------
            | This allows category tree structure.
            | Example:
            | Geological Documents
            |   - Soil Reports
            |   - Mineral Reports
            */
            $table->foreignId('parent_id')
                ->nullable()
                ->constrained('document_categories')
                ->nullOnDelete();

            /*
            |--------------------------------------------------------------------------
            | Created By
            |--------------------------------------------------------------------------
            | Shows which admin or document controller created this category.
            */
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('name');
            $table->string('slug')->unique();

            $table->text('description')->nullable();

            /*
            |--------------------------------------------------------------------------
            | Status
            |--------------------------------------------------------------------------
            | active = can be used when uploading documents
            | inactive = hidden or disabled
            */
            $table->enum('status', ['active', 'inactive'])->default('active');

            /*
            |--------------------------------------------------------------------------
            | Sort Order
            |--------------------------------------------------------------------------
            | Used to arrange categories in frontend.
            */
            $table->integer('sort_order')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['parent_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_categories');
    }
};