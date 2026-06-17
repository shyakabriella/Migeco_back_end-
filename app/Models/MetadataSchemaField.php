<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MetadataSchemaField extends Model
{
    use HasFactory;

    protected $fillable = [
        'metadata_schema_id',
        'field_key',
        'label',
        'field_type',
        'unit',
        'options',
        'validation_rules',
        'is_required',
        'is_searchable',
        'is_filterable',
        'sort_order',
    ];

    protected $casts = [
        'options' => 'array',
        'validation_rules' => 'array',
        'is_required' => 'boolean',
        'is_searchable' => 'boolean',
        'is_filterable' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function schema(): BelongsTo
    {
        return $this->belongsTo(MetadataSchema::class, 'metadata_schema_id');
    }
}
