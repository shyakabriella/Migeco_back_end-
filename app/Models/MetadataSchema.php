<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MetadataSchema extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'record_type',
        'version',
        'status',
        'is_system',
        'created_by',
    ];

    protected $casts = [
        'version' => 'integer',
        'is_system' => 'boolean',
    ];

    public function fields(): HasMany
    {
        return $this->hasMany(MetadataSchemaField::class)
            ->orderBy('sort_order')
            ->orderBy('label');
    }

    public function geologicalRecords(): HasMany
    {
        return $this->hasMany(GeologicalRecord::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
