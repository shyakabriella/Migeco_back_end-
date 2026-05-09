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
        Schema::create('document_scan_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('document_id')
                ->constrained('documents')
                ->cascadeOnDelete();

            $table->foreignId('scanned_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            /*
            |--------------------------------------------------------------------------
            | Scan Information
            |--------------------------------------------------------------------------
            */
            $table->string('scan_engine')->default('clamav');

            $table->enum('scan_type', [
                'manual',
                'automatic',
                'batch',
            ])->default('manual');

            $table->enum('status', [
                'pending',
                'clean',
                'infected',
                'failed',
            ])->default('pending');

            $table->string('threat_name')->nullable();

            $table->string('file_path')->nullable();
            $table->string('sha256_hash', 64)->nullable();

            $table->text('message')->nullable();
            $table->longText('raw_output')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->index(['document_id', 'status']);
            $table->index(['scanned_by']);
            $table->index(['scan_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_scan_logs');
    }
};