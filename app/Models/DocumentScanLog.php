<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentScanLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'document_id',
        'scanned_by',
        'scan_engine',
        'scan_type',
        'status',
        'threat_name',
        'file_path',
        'sha256_hash',
        'message',
        'raw_output',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'document_id' => 'integer',
            'scanned_by' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * Scan log belongs to one document.
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * User who scanned the document.
     */
    public function scanner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scanned_by');
    }
}