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
            $table->foreignId('ai_analyzed_by')
                ->nullable()
                ->after('ai_status')
                ->constrained('users')
                ->nullOnDelete();

            $table->string('ai_provider')->nullable()->after('ai_analyzed_by');
            $table->string('ai_model')->nullable()->after('ai_provider');

            $table->longText('ai_summary')->nullable()->after('ai_model');

            $table->string('ai_detected_language')->nullable()->after('ai_summary');

            $table->decimal('ai_confidence_score', 5, 2)
                ->nullable()
                ->after('ai_detected_language');

            $table->enum('ai_sensitivity_level', [
                'public',
                'internal',
                'confidential',
                'restricted',
            ])->nullable()->after('ai_confidence_score');

            $table->foreignId('ai_suggested_category_id')
                ->nullable()
                ->after('ai_sensitivity_level')
                ->constrained('document_categories')
                ->nullOnDelete();

            $table->string('ai_suggested_document_type')->nullable()->after('ai_suggested_category_id');

            $table->json('ai_suggested_tags')->nullable()->after('ai_suggested_document_type');
            $table->json('ai_key_points')->nullable()->after('ai_suggested_tags');
            $table->json('ai_detected_risks')->nullable()->after('ai_key_points');
            $table->json('ai_entities')->nullable()->after('ai_detected_risks');
            $table->json('ai_recommended_actions')->nullable()->after('ai_entities');

            $table->timestamp('ai_analyzed_at')->nullable()->after('ai_recommended_actions');

            $table->index(['ai_status']);
            $table->index(['ai_analyzed_by']);
            $table->index(['ai_sensitivity_level']);
            $table->index(['ai_suggested_category_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropIndex(['ai_status']);
            $table->dropIndex(['ai_analyzed_by']);
            $table->dropIndex(['ai_sensitivity_level']);
            $table->dropIndex(['ai_suggested_category_id']);

            $table->dropForeign(['ai_analyzed_by']);
            $table->dropForeign(['ai_suggested_category_id']);

            $table->dropColumn([
                'ai_analyzed_by',
                'ai_provider',
                'ai_model',
                'ai_summary',
                'ai_detected_language',
                'ai_confidence_score',
                'ai_sensitivity_level',
                'ai_suggested_category_id',
                'ai_suggested_document_type',
                'ai_suggested_tags',
                'ai_key_points',
                'ai_detected_risks',
                'ai_entities',
                'ai_recommended_actions',
                'ai_analyzed_at',
            ]);
        });
    }
};