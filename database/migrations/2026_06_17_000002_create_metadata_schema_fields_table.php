<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('metadata_schema_fields', function (Blueprint $table) {
            $table->id();

            $table->foreignId('metadata_schema_id')
                ->constrained('metadata_schemas')
                ->cascadeOnDelete();

            $table->string('field_key');
            $table->string('label');

            /*
            |--------------------------------------------------------------------------
            | Supported field types
            |--------------------------------------------------------------------------
            | text, textarea, number, date, boolean, select, multi_select
            |
            | A string column is used instead of a database enum so that future
            | field types can be added without changing old records.
            */
            $table->string('field_type')->default('text');

            $table->string('unit')->nullable();
            $table->json('options')->nullable();
            $table->json('validation_rules')->nullable();

            $table->boolean('is_required')->default(false);
            $table->boolean('is_searchable')->default(true);
            $table->boolean('is_filterable')->default(false);

            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->unique(
                ['metadata_schema_id', 'field_key'],
                'metadata_schema_field_unique'
            );

            $table->index(
                ['metadata_schema_id', 'sort_order'],
                'metadata_schema_field_sort_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metadata_schema_fields');
    }
};
