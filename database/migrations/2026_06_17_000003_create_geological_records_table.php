<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('geological_records', function (Blueprint $table) {
            $table->id();

            /*
            |--------------------------------------------------------------------------
            | Additive design
            |--------------------------------------------------------------------------
            | One document may have one structured geological record.
            | Existing documents continue working even when they do not have one.
            */
            $table->foreignId('document_id')
                ->unique()
                ->constrained('documents')
                ->cascadeOnDelete();

            $table->foreignId('metadata_schema_id')
                ->nullable()
                ->constrained('metadata_schemas')
                ->nullOnDelete();

            $table->string('record_type')->index();

            /*
            |--------------------------------------------------------------------------
            | General geological identification
            |--------------------------------------------------------------------------
            */
            $table->string('site_name')->nullable();
            $table->string('survey_name')->nullable();
            $table->date('survey_date')->nullable()->index();
            $table->string('geologist_name')->nullable();
            $table->string('organization')->nullable();

            /*
            |--------------------------------------------------------------------------
            | Administrative and spatial location
            |--------------------------------------------------------------------------
            */
            $table->string('country')->default('Rwanda');
            $table->string('province')->nullable();
            $table->string('district')->nullable()->index();
            $table->string('sector')->nullable();
            $table->string('cell')->nullable();
            $table->string('village')->nullable();

            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->decimal('elevation', 10, 2)->nullable();
            $table->string('coordinate_reference_system')
                ->nullable()
                ->default('EPSG:4326');

            /*
            |--------------------------------------------------------------------------
            | Geological description
            |--------------------------------------------------------------------------
            */
            $table->string('geological_formation')->nullable();
            $table->string('rock_type')->nullable()->index();
            $table->string('mineral_name')->nullable()->index();
            $table->string('commodity')->nullable()->index();
            $table->string('source_method')->nullable();
            $table->string('data_quality')->nullable();

            /*
            |--------------------------------------------------------------------------
            | Borehole and groundwater fields
            |--------------------------------------------------------------------------
            */
            $table->string('borehole_code')->nullable()->index();
            $table->decimal('total_depth', 12, 3)->nullable();
            $table->decimal('water_level', 12, 3)->nullable();
            $table->string('aquifer_name')->nullable();
            $table->string('aquifer_type')->nullable();
            $table->decimal('yield_rate', 12, 3)->nullable();

            /*
            |--------------------------------------------------------------------------
            | Fault and structural geology fields
            |--------------------------------------------------------------------------
            */
            $table->string('fault_name')->nullable();
            $table->string('fault_type')->nullable();
            $table->decimal('strike', 8, 3)->nullable();
            $table->decimal('dip', 8, 3)->nullable();
            $table->string('dip_direction')->nullable();

            /*
            |--------------------------------------------------------------------------
            | Flexible schema values
            |--------------------------------------------------------------------------
            | Important common fields are stored as columns for reliable search.
            | Less common fields remain in custom_metadata.
            */
            $table->json('custom_metadata')->nullable();
            $table->unsignedInteger('metadata_version')->default(1);

            $table->text('notes')->nullable();

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(
                ['record_type', 'district'],
                'geological_record_type_district_index'
            );

            $table->index(
                ['latitude', 'longitude'],
                'geological_location_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('geological_records');
    }
};
