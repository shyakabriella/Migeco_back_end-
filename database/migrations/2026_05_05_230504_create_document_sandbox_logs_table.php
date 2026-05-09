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
        Schema::create('document_sandbox_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('document_id')
                ->constrained('documents')
                ->cascadeOnDelete();

            $table->foreignId('tested_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->enum('sandbox_type', [
                'manual',
                'automatic',
                'batch',
            ])->default('manual');

            $table->enum('status', [
                'pending',
                'safe',
                'unsafe',
                'failed',
            ])->default('pending');

            $table->unsignedInteger('risk_score')->default(0);

            $table->json('indicators')->nullable();
            $table->json('report')->nullable();

            $table->string('source_file_path')->nullable();
            $table->string('source_extension', 20)->nullable();
            $table->string('source_mime_type')->nullable();

            $table->text('message')->nullable();
            $table->longText('error_details')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->index(['document_id', 'status']);
            $table->index(['tested_by']);
            $table->index(['sandbox_type']);
            $table->index(['risk_score']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_sandbox_logs');
    }
};