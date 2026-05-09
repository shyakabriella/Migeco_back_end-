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
        Schema::create('document_encryption_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('document_id')
                ->constrained('documents')
                ->cascadeOnDelete();

            $table->foreignId('performed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->enum('action', [
                'encrypt',
                'decrypt_for_view',
                'decrypt_for_download',
                'verify',
                'failed',
            ])->default('encrypt');

            $table->enum('status', [
                'success',
                'failed',
            ])->default('success');

            $table->string('algorithm')->nullable();
            $table->string('key_id')->nullable();

            $table->string('source_file_path')->nullable();
            $table->string('encrypted_file_path')->nullable();

            $table->string('source_sha256_hash', 64)->nullable();
            $table->string('encrypted_sha256_hash', 64)->nullable();

            $table->text('message')->nullable();
            $table->longText('error_details')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->index(['document_id', 'action']);
            $table->index(['performed_by']);
            $table->index(['status']);
            $table->index(['key_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_encryption_logs');
    }
};