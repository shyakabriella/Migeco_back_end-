<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class LaboratoryResultDocument extends Model
{
    protected $table = 'laboratory_result_documents';

    protected $fillable = [
        'sample_record_id',
        'laboratory_result_id',
        'document_type',
        'title',
        'original_file_name',
        'stored_file_name',
        'file_path',
        'file_size',
        'mime_type',
        'uploaded_by',
    ];

    protected $casts = [
        'sample_record_id' => 'integer',
        'laboratory_result_id' => 'integer',
        'file_size' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $appends = [
        'url',
        'originalFileName',
        'fileSize',
        'mimeType',
    ];

    public function sampleRecord(): BelongsTo
    {
        return $this->belongsTo(SampleRecord::class, 'sample_record_id');
    }

    public function laboratoryResult(): BelongsTo
    {
        return $this->belongsTo(LaboratoryResult::class, 'laboratory_result_id');
    }

    public function getUrlAttribute(): ?string
    {
        if (!$this->file_path) {
            return null;
        }

        return Storage::disk('public')->url($this->file_path);
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