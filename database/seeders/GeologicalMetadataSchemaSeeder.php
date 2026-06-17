<?php

namespace Database\Seeders;

use App\Models\MetadataSchema;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GeologicalMetadataSchemaSeeder extends Seeder
{
    public function run(): void
    {
        $schemas = [
            [
                'name' => 'General Geological Record',
                'record_type' => 'general_geological_record',
                'description' => 'General metadata used by geological reports, maps, surveys, and field records.',
                'fields' => [
                    ['field_key' => 'map_sheet_number', 'label' => 'Map Sheet Number', 'field_type' => 'text', 'is_filterable' => true],
                    ['field_key' => 'map_scale', 'label' => 'Map Scale', 'field_type' => 'text', 'is_filterable' => true],
                    ['field_key' => 'geological_age', 'label' => 'Geological Age', 'field_type' => 'text', 'is_filterable' => true],
                    ['field_key' => 'interpretation_status', 'label' => 'Interpretation Status', 'field_type' => 'select', 'options' => ['preliminary', 'reviewed', 'final'], 'is_filterable' => true],
                ],
            ],
            [
                'name' => 'Borehole Record',
                'record_type' => 'borehole',
                'description' => 'Structured metadata for boreholes, drilling, lithology, and groundwater observations.',
                'fields' => [
                    ['field_key' => 'drilling_date', 'label' => 'Drilling Date', 'field_type' => 'date', 'is_required' => true, 'is_filterable' => true],
                    ['field_key' => 'drilling_method', 'label' => 'Drilling Method', 'field_type' => 'text', 'is_filterable' => true],
                    ['field_key' => 'diameter_mm', 'label' => 'Diameter', 'field_type' => 'number', 'unit' => 'mm'],
                    ['field_key' => 'static_water_level_m', 'label' => 'Static Water Level', 'field_type' => 'number', 'unit' => 'm'],
                    ['field_key' => 'dynamic_water_level_m', 'label' => 'Dynamic Water Level', 'field_type' => 'number', 'unit' => 'm'],
                    ['field_key' => 'completion_status', 'label' => 'Completion Status', 'field_type' => 'select', 'options' => ['completed', 'abandoned', 'monitoring'], 'is_filterable' => true],
                ],
            ],
            [
                'name' => 'Rock Sample Record',
                'record_type' => 'rock_sample',
                'description' => 'Metadata for rock samples, mineral observations, and field sample collection.',
                'fields' => [
                    ['field_key' => 'sample_code', 'label' => 'Sample Code', 'field_type' => 'text', 'is_required' => true, 'is_filterable' => true],
                    ['field_key' => 'sample_depth_m', 'label' => 'Sample Depth', 'field_type' => 'number', 'unit' => 'm'],
                    ['field_key' => 'sample_method', 'label' => 'Sampling Method', 'field_type' => 'text', 'is_filterable' => true],
                    ['field_key' => 'weathering_grade', 'label' => 'Weathering Grade', 'field_type' => 'text', 'is_filterable' => true],
                    ['field_key' => 'laboratory_reference', 'label' => 'Laboratory Reference', 'field_type' => 'text'],
                ],
            ],
            [
                'name' => 'Groundwater Record',
                'record_type' => 'groundwater',
                'description' => 'Metadata for aquifers, groundwater levels, yields, and water quality.',
                'fields' => [
                    ['field_key' => 'sampling_date', 'label' => 'Sampling Date', 'field_type' => 'date', 'is_required' => true, 'is_filterable' => true],
                    ['field_key' => 'ph', 'label' => 'pH', 'field_type' => 'number'],
                    ['field_key' => 'conductivity_us_cm', 'label' => 'Conductivity', 'field_type' => 'number', 'unit' => 'µS/cm'],
                    ['field_key' => 'temperature_c', 'label' => 'Temperature', 'field_type' => 'number', 'unit' => '°C'],
                    ['field_key' => 'water_quality_class', 'label' => 'Water Quality Class', 'field_type' => 'select', 'options' => ['potable', 'non_potable', 'requires_treatment', 'unknown'], 'is_filterable' => true],
                ],
            ],
            [
                'name' => 'Fault and Structure Record',
                'record_type' => 'fault_structure',
                'description' => 'Metadata for faults, fractures, folds, and structural measurements.',
                'fields' => [
                    ['field_key' => 'structure_type', 'label' => 'Structure Type', 'field_type' => 'select', 'options' => ['fault', 'fracture', 'fold', 'joint', 'shear_zone', 'other'], 'is_required' => true, 'is_filterable' => true],
                    ['field_key' => 'movement_type', 'label' => 'Movement Type', 'field_type' => 'text', 'is_filterable' => true],
                    ['field_key' => 'estimated_length_m', 'label' => 'Estimated Length', 'field_type' => 'number', 'unit' => 'm'],
                    ['field_key' => 'activity_status', 'label' => 'Activity Status', 'field_type' => 'select', 'options' => ['active', 'inactive', 'unknown'], 'is_filterable' => true],
                ],
            ],
            [
                'name' => 'Mineral Occurrence Record',
                'record_type' => 'mineral_occurrence',
                'description' => 'Metadata for mineral occurrences, commodities, grades, and assay results.',
                'fields' => [
                    ['field_key' => 'occurrence_code', 'label' => 'Occurrence Code', 'field_type' => 'text', 'is_required' => true, 'is_filterable' => true],
                    ['field_key' => 'deposit_type', 'label' => 'Deposit Type', 'field_type' => 'text', 'is_filterable' => true],
                    ['field_key' => 'grade_value', 'label' => 'Grade Value', 'field_type' => 'number'],
                    ['field_key' => 'grade_unit', 'label' => 'Grade Unit', 'field_type' => 'text'],
                    ['field_key' => 'assay_method', 'label' => 'Assay Method', 'field_type' => 'text', 'is_filterable' => true],
                    ['field_key' => 'resource_status', 'label' => 'Resource Status', 'field_type' => 'select', 'options' => ['occurrence', 'prospect', 'deposit', 'mine', 'unknown'], 'is_filterable' => true],
                ],
            ],
        ];

        DB::transaction(function () use ($schemas) {
            foreach ($schemas as $schemaData) {
                $schema = MetadataSchema::withTrashed()
                    ->where('name', $schemaData['name'])
                    ->first();

                if (!$schema) {
                    $schema = MetadataSchema::create([
                        'name' => $schemaData['name'],
                        'slug' => Str::slug($schemaData['name']),
                        'description' => $schemaData['description'],
                        'record_type' => $schemaData['record_type'],
                        'version' => 1,
                        'status' => 'active',
                        'is_system' => true,
                        'created_by' => null,
                    ]);
                } else {
                    if ($schema->trashed()) {
                        $schema->restore();
                    }

                    $schema->update([
                        'description' => $schemaData['description'],
                        'record_type' => $schemaData['record_type'],
                        'status' => 'active',
                        'is_system' => true,
                    ]);
                }

                $schema->fields()->delete();

                foreach ($schemaData['fields'] as $index => $field) {
                    $schema->fields()->create([
                        'field_key' => $field['field_key'],
                        'label' => $field['label'],
                        'field_type' => $field['field_type'] ?? 'text',
                        'unit' => $field['unit'] ?? null,
                        'options' => $field['options'] ?? null,
                        'validation_rules' => $field['validation_rules'] ?? null,
                        'is_required' => (bool) ($field['is_required'] ?? false),
                        'is_searchable' => (bool) ($field['is_searchable'] ?? true),
                        'is_filterable' => (bool) ($field['is_filterable'] ?? false),
                        'sort_order' => $index + 1,
                    ]);
                }
            }
        });
    }
}
