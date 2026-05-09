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
        Schema::table('documents', function (Blueprint $table) {
            $table->string('plaintext_file_path')->nullable()->after('plaintext_status');

            $table->foreignId('plaintext_extracted_by')
                ->nullable()
                ->after('plaintext_file_path')
                ->constrained('users')
                ->nullOnDelete();

            $table->unsignedBigInteger('plaintext_character_count')
                ->nullable()
                ->after('plaintext_extracted_by');

            $table->unsignedBigInteger('plaintext_word_count')
                ->nullable()
                ->after('plaintext_character_count');

            $table->string('plaintext_sha256_hash', 64)
                ->nullable()
                ->after('plaintext_word_count');

            $table->text('plaintext_preview')
                ->nullable()
                ->after('plaintext_sha256_hash');

            $table->timestamp('plaintext_extracted_at')
                ->nullable()
                ->after('plaintext_preview');

            $table->index(['plaintext_status']);
            $table->index(['plaintext_extracted_by']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropIndex(['plaintext_status']);
            $table->dropIndex(['plaintext_extracted_by']);

            $table->dropForeign(['plaintext_extracted_by']);

            $table->dropColumn([
                'plaintext_file_path',
                'plaintext_extracted_by',
                'plaintext_character_count',
                'plaintext_word_count',
                'plaintext_sha256_hash',
                'plaintext_preview',
                'plaintext_extracted_at',
            ]);
        });
    }
};