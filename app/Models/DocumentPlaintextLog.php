<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentPlaintextLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'document_id',
        'performed_by',
        'extraction_type',
        'status',
        'extraction_engine',
        'source_file_path',
        'plaintext_file_path',
        'character_count',
        'word_count',
        'sha256_hash',
        'message',
        'error_details',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'document_id' => 'integer',
            'performed_by' => 'integer',
            'character_count' => 'integer',
            'word_count' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * Log belongs to one document.
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * User who performed extraction.
     */
    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}