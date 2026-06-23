<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class StudyArea extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'project_id',
        'created_by',
        'updated_by',

        'name',
        'code',
        'slug',
        'project_name',
        'description',

        'province',
        'district',
        'sector',
        'cell',
        'village',
        'location_name',
        'latitude',
        'longitude',
        'elevation',
        'area_size',

        'map_title',
        'map_type',
        'map_reference',
        'map_scale',
        'coordinate_system',
        'location_accuracy',
        'access_route',
        'field_team',

        'status',
        'last_surveyed',
        'notes',
        'metadata',
    ];

    protected $casts = [
        'project_id' => 'integer',
        'created_by' => 'integer',
        'updated_by' => 'integer',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'last_surveyed' => 'date:Y-m-d',
        'metadata' => 'array',
        'deleted_at' => 'datetime',
    ];

    protected $appends = [
        'photos_count',
        'primary_photo_url',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(StudyAreaPhoto::class)->orderBy('sort_order')->orderBy('id');
    }

    public function getPhotosCountAttribute(): int
    {
        if ($this->relationLoaded('photos')) {
            return $this->photos->count();
        }

        return (int) ($this->attributes['photos_count'] ?? $this->photos()->count());
    }

    public function getPrimaryPhotoUrlAttribute(): ?string
    {
        $photo = null;

        if ($this->relationLoaded('photos')) {
            $photo = $this->photos->first();
        } else {
            $photo = $this->photos()->first();
        }

        if (!$photo) {
            return null;
        }

        if ($photo->url) {
            return $photo->url;
        }

        if ($photo->file_path) {
            return Storage::disk($photo->disk ?: 'public')->url($photo->file_path);
        }

        return null;
    }
}