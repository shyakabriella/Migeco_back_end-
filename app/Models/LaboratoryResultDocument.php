<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class LaboratoryResultDocument extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'sample_record_id',
        'laboratory_result_id',
        'document_id',
        'uploaded_by',
        'document_type',
        'title',
        'description',
        'original_file_name',
        'stored_file_name',
        'file_path',
        'file_disk',
        'file_extension',
        'mime_type',
        'file_size',
        'sha256_hash',
        'metadata',
    ];

    protected $casts = [
        'sample_record_id' => 'integer',
        'laboratory_result_id' => 'integer',
        'document_id' => 'integer',
        'uploaded_by' => 'integer',
        'file_size' => 'integer',
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected $appends = [
        'url',
        'originalFileName',
        'fileSize',
        'mimeType',
    ];

    public function sample(): BelongsTo
    {
        return $this->belongsTo(SampleRecord::class, 'sample_record_id');
    }

    public function laboratoryResult(): BelongsTo
    {
        return $this->belongsTo(LaboratoryResult::class, 'laboratory_result_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
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

        if ($this->file_disk === 'public') {
            return Storage::disk('public')->url($this->file_path);
        }

        return null;
    }

    public function getOriginalFileNameAttribute(): ?string
    {
        return $this->original_file_name;
    }

    public function getFileSizeAttribute(): ?int
    {
        return $this->file_size;
    }

    public function getMimeTypeAttribute(): ?string
    {
        return $this->mime_type;
    }
}