<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Project extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'created_by',
        'name',
        'code',
        'description',
        'location_name',
        'latitude',
        'longitude',
        'project_type',
        'status',
        'security_level',
        'start_date',
        'end_date',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'created_by' => 'integer',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'start_date' => 'date',
            'end_date' => 'date',
            'metadata' => 'array',
        ];
    }

    /**
     * User who created the project.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Active projects only.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Check if project is archived.
     */
    public function isArchived(): bool
    {
        return $this->status === 'archived';
    }

    /**
     * Check if project is restricted.
     */
    public function isRestricted(): bool
    {
        return $this->security_level === 'restricted';
    }
}