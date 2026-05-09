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
        Schema::create('document_plaintext_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('document_id')
                ->constrained('documents')
                ->cascadeOnDelete();

            $table->foreignId('performed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->enum('extraction_type', [
                'manual',
                'automatic',
                'batch',
            ])->default('manual');

            $table->enum('status', [
                'pending',
                'extracted',
                'failed',
                'unsupported',
            ])->default('pending');

            $table->string('extraction_engine')->nullable();

            $table->string('source_file_path')->nullable();
            $table->string('plaintext_file_path')->nullable();

            $table->unsignedBigInteger('character_count')->nullable();
            $table->unsignedBigInteger('word_count')->nullable();

            $table->string('sha256_hash', 64)->nullable();

            $table->text('message')->nullable();
            $table->longText('error_details')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->index(['document_id', 'status']);
            $table->index(['performed_by']);
            $table->index(['extraction_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_plaintext_logs');
    }
};