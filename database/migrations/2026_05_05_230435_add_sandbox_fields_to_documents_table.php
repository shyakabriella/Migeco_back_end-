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
            $table->foreignId('sandbox_tested_by')
                ->nullable()
                ->after('sandbox_status')
                ->constrained('users')
                ->nullOnDelete();

            $table->unsignedInteger('sandbox_score')
                ->nullable()
                ->after('sandbox_tested_by');

            $table->text('sandbox_message')
                ->nullable()
                ->after('sandbox_score');

            $table->json('sandbox_report')
                ->nullable()
                ->after('sandbox_message');

            $table->timestamp('sandbox_tested_at')
                ->nullable()
                ->after('sandbox_report');

            $table->index(['sandbox_status']);
            $table->index(['sandbox_tested_by']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropIndex(['sandbox_status']);
            $table->dropIndex(['sandbox_tested_by']);
            $table->dropForeign(['sandbox_tested_by']);

            $table->dropColumn([
                'sandbox_tested_by',
                'sandbox_score',
                'sandbox_message',
                'sandbox_report',
                'sandbox_tested_at',
            ]);
        });
    }
};