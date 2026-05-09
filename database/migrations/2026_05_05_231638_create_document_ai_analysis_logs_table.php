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
        Schema::create('document_ai_analysis_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('document_id')
                ->constrained('documents')
                ->cascadeOnDelete();

            $table->foreignId('performed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->enum('analysis_type', [
                'manual',
                'automatic',
                'batch',
            ])->default('manual');

            $table->enum('status', [
                'pending',
                'analyzed',
                'failed',
            ])->default('pending');

            $table->string('provider')->nullable();
            $table->string('model')->nullable();

            $table->unsignedBigInteger('input_character_count')->nullable();

            $table->text('message')->nullable();
            $table->longText('error_details')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->index(['document_id', 'status']);
            $table->index(['performed_by']);
            $table->index(['analysis_type']);
            $table->index(['provider']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_ai_analysis_logs');
    }
};