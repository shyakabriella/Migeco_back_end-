<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class StudyAreaPhoto extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'study_area_id',
        'uploaded_by',
        'caption',
        'original_file_name',
        'stored_file_name',
        'file_path',
        'disk',
        'mime_type',
        'extension',
        'file_size',
        'captured_at',
        'sort_order',
        'metadata',
    ];

    protected $casts = [
        'study_area_id' => 'integer',
        'uploaded_by' => 'integer',
        'file_size' => 'integer',
        'captured_at' => 'datetime',
        'sort_order' => 'integer',
        'metadata' => 'array',
        'deleted_at' => 'datetime',
    ];

    protected $appends = [
        'url',
    ];

    public function studyArea(): BelongsTo
    {
        return $this->belongsTo(StudyArea::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function getUrlAttribute(): ?string
    {
        if (!$this->file_path) {
            return null;
        }

        return Storage::disk($this->disk ?: 'public')->url($this->file_path);
    }
}