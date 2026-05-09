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
        Schema::create('document_plaintexts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('document_id')
                ->constrained('documents')
                ->cascadeOnDelete();

            $table->foreignId('extracted_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            /*
            |--------------------------------------------------------------------------
            | Plaintext Content
            |--------------------------------------------------------------------------
            */
            $table->longText('content')->nullable();

            $table->string('plaintext_file_path')->nullable();

            $table->string('extraction_engine')->nullable();
            $table->string('source_extension', 20)->nullable();
            $table->string('source_mime_type')->nullable();

            $table->unsignedBigInteger('character_count')->default(0);
            $table->unsignedBigInteger('word_count')->default(0);

            $table->string('sha256_hash', 64)->nullable();

            $table->text('preview')->nullable();

            $table->enum('status', [
                'extracted',
                'failed',
            ])->default('extracted');

            $table->text('message')->nullable();

            $table->timestamps();

            $table->unique('document_id');

            $table->index(['extracted_by']);
            $table->index(['status']);
            $table->index(['source_extension']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_plaintexts');
    }
};