<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->foreignId('archived_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('archived_at')
                ->nullable();

            $table->text('archive_reason')
                ->nullable();

            $table->foreignId('restored_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('restored_at')
                ->nullable();

            $table->text('restore_reason')
                ->nullable();

            $table->index(['status', 'archived_at']);
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropIndex(['status', 'archived_at']);

            $table->dropConstrainedForeignId('archived_by');
            $table->dropConstrainedForeignId('restored_by');

            $table->dropColumn([
                'archived_at',
                'archive_reason',
                'restored_at',
                'restore_reason',
            ]);
        });
    }
};
