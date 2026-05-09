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
        Schema::create('documents', function (Blueprint $table) {
            $table->id();

            /*
            |--------------------------------------------------------------------------
            | Relations
            |--------------------------------------------------------------------------
            */
            $table->foreignId('project_id')
                ->nullable()
                ->constrained('projects')
                ->nullOnDelete();

            $table->foreignId('document_category_id')
                ->nullable()
                ->constrained('document_categories')
                ->nullOnDelete();

            $table->foreignId('uploaded_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('approved_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            /*
            |--------------------------------------------------------------------------
            | Document Basic Information
            |--------------------------------------------------------------------------
            */
            $table->string('document_code')->unique();
            $table->string('title');
            $table->string('slug')->nullable();
            $table->text('description')->nullable();

            /*
            |--------------------------------------------------------------------------
            | Document Type
            |--------------------------------------------------------------------------
            */
            $table->enum('document_type', [
                'geological_report',
                'technical_drawing',
                'construction_record',
                'survey_map',
                'contract',
                'plain_text',
                'image',
                'spreadsheet',
                'presentation',
                'other',
            ])->default('other');

            /*
            |--------------------------------------------------------------------------
            | File Information
            |--------------------------------------------------------------------------
            */
            $table->string('original_file_name');
            $table->string('stored_file_name');
            $table->string('file_path');
            $table->string('disk')->default('local');

            $table->string('mime_type')->nullable();
            $table->string('extension', 20)->nullable();
            $table->unsignedBigInteger('file_size')->default(0);

            /*
            |--------------------------------------------------------------------------
            | File Integrity / Tamper Protection
            |--------------------------------------------------------------------------
            | SHA256 hash helps us know if file content was changed.
            */
            $table->string('sha256_hash', 64)->nullable();

            /*
            |--------------------------------------------------------------------------
            | Version
            |--------------------------------------------------------------------------
            | First upload starts at version 1.
            */
            $table->unsignedInteger('version_number')->default(1);

            /*
            |--------------------------------------------------------------------------
            | Security Level
            |--------------------------------------------------------------------------
            */
            $table->enum('security_level', [
                'public',
                'internal',
                'confidential',
                'restricted',
            ])->default('internal');

            /*
            |--------------------------------------------------------------------------
            | Document Status
            |--------------------------------------------------------------------------
            | pending_scan = uploaded but not yet safe
            | active = safe and usable
            | quarantined = infected or suspicious
            | rejected = blocked by security officer/admin
            | archived = old document
            */
            $table->enum('status', [
                'pending_scan',
                'active',
                'quarantined',
                'rejected',
                'archived',
            ])->default('pending_scan');

            /*
            |--------------------------------------------------------------------------
            | Antivirus Status
            |--------------------------------------------------------------------------
            */
            $table->enum('scan_status', [
                'pending',
                'clean',
                'infected',
                'failed',
            ])->default('pending');

            $table->text('scan_message')->nullable();
            $table->timestamp('scanned_at')->nullable();

            /*
            |--------------------------------------------------------------------------
            | Cryptography Status
            |--------------------------------------------------------------------------
            */
            $table->enum('encryption_status', [
                'not_encrypted',
                'pending',
                'encrypted',
                'failed',
            ])->default('not_encrypted');

            /*
            |--------------------------------------------------------------------------
            | Plaintext Extraction Status
            |--------------------------------------------------------------------------
            */
            $table->enum('plaintext_status', [
                'not_extracted',
                'pending',
                'extracted',
                'failed',
            ])->default('not_extracted');

            /*
            |--------------------------------------------------------------------------
            | Sandbox Status
            |--------------------------------------------------------------------------
            */
            $table->enum('sandbox_status', [
                'not_tested',
                'pending',
                'safe',
                'unsafe',
                'failed',
            ])->default('not_tested');

            /*
            |--------------------------------------------------------------------------
            | AI Analysis Status
            |--------------------------------------------------------------------------
            */
            $table->enum('ai_status', [
                'not_analyzed',
                'pending',
                'analyzed',
                'failed',
            ])->default('not_analyzed');

            /*
            |--------------------------------------------------------------------------
            | Extra Data
            |--------------------------------------------------------------------------
            */
            $table->json('tags')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamp('approved_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['project_id', 'document_category_id']);
            $table->index(['uploaded_by']);
            $table->index(['status', 'scan_status']);
            $table->index(['security_level']);
            $table->index(['document_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};