<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentSandboxLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'document_id',
        'tested_by',
        'sandbox_type',
        'status',
        'risk_score',
        'indicators',
        'report',
        'source_file_path',
        'source_extension',
        'source_mime_type',
        'message',
        'error_details',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'document_id' => 'integer',
            'tested_by' => 'integer',
            'risk_score' => 'integer',
            'indicators' => 'array',
            'report' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * Sandbox log belongs to one document.
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * User who tested the document.
     */
    public function tester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tested_by');
    }
}