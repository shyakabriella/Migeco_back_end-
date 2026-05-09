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
            /*
            |--------------------------------------------------------------------------
            | Encryption Details
            |--------------------------------------------------------------------------
            | These columns help us track encrypted file storage and encryption metadata.
            */
            $table->string('original_clean_file_path')->nullable()->after('file_path');
            $table->string('encrypted_file_path')->nullable()->after('original_clean_file_path');

            $table->string('encryption_algorithm')->nullable()->after('encryption_status');
            $table->string('encryption_key_id')->nullable()->after('encryption_algorithm');

            $table->unsignedBigInteger('encrypted_file_size')->nullable()->after('encryption_key_id');
            $table->string('encrypted_sha256_hash', 64)->nullable()->after('encrypted_file_size');

            $table->timestamp('encrypted_at')->nullable()->after('encrypted_sha256_hash');

            $table->index(['encryption_status']);
            $table->index(['encryption_key_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropIndex(['encryption_status']);
            $table->dropIndex(['encryption_key_id']);

            $table->dropColumn([
                'original_clean_file_path',
                'encrypted_file_path',
                'encryption_algorithm',
                'encryption_key_id',
                'encrypted_file_size',
                'encrypted_sha256_hash',
                'encrypted_at',
            ]);
        });
    }
};