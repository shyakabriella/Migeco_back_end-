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
        Schema::create('document_ai_analyses', function (Blueprint $table) {
            $table->id();

            $table->foreignId('document_id')
                ->constrained('documents')
                ->cascadeOnDelete();

            $table->foreignId('analyzed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('provider')->default('local');
            $table->string('model')->nullable();

            $table->longText('summary')->nullable();

            $table->string('detected_language')->nullable();

            $table->decimal('confidence_score', 5, 2)->nullable();

            $table->enum('sensitivity_level', [
                'public',
                'internal',
                'confidential',
                'restricted',
            ])->default('internal');

            $table->foreignId('suggested_category_id')
                ->nullable()
                ->constrained('document_categories')
                ->nullOnDelete();

            $table->string('suggested_document_type')->nullable();

            $table->json('suggested_tags')->nullable();
            $table->json('key_points')->nullable();
            $table->json('detected_risks')->nullable();
            $table->json('entities')->nullable();
            $table->json('recommended_actions')->nullable();
            $table->json('raw_response')->nullable();

            $table->enum('status', [
                'analyzed',
                'failed',
            ])->default('analyzed');

            $table->text('message')->nullable();

            $table->timestamps();

            $table->unique('document_id');

            $table->index(['analyzed_by']);
            $table->index(['provider']);
            $table->index(['status']);
            $table->index(['sensitivity_level']);
            $table->index(['suggested_category_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_ai_analyses');
    }
};