<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class GeologicalRecord extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'document_id',
        'metadata_schema_id',
        'record_type',

        'site_name',
        'survey_name',
        'survey_date',
        'geologist_name',
        'organization',

        'country',
        'province',
        'district',
        'sector',
        'cell',
        'village',

        'latitude',
        'longitude',
        'elevation',
        'coordinate_reference_system',

        'geological_formation',
        'rock_type',
        'mineral_name',
        'commodity',
        'source_method',
        'data_quality',

        'borehole_code',
        'total_depth',
        'water_level',
        'aquifer_name',
        'aquifer_type',
        'yield_rate',

        'fault_name',
        'fault_type',
        'strike',
        'dip',
        'dip_direction',

        'custom_metadata',
        'metadata_version',
        'notes',

        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'survey_date' => 'date:Y-m-d',

        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'elevation' => 'decimal:2',

        'total_depth' => 'decimal:3',
        'water_level' => 'decimal:3',
        'yield_rate' => 'decimal:3',

        'strike' => 'decimal:3',
        'dip' => 'decimal:3',

        'custom_metadata' => 'array',
        'metadata_version' => 'integer',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function schema(): BelongsTo
    {
        return $this->belongsTo(MetadataSchema::class, 'metadata_schema_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
