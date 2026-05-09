<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentPlaintext extends Model
{
    use HasFactory;

    protected $fillable = [
        'document_id',
        'extracted_by',
        'content',
        'plaintext_file_path',
        'extraction_engine',
        'source_extension',
        'source_mime_type',
        'character_count',
        'word_count',
        'sha256_hash',
        'preview',
        'status',
        'message',
    ];

    protected function casts(): array
    {
        return [
            'document_id' => 'integer',
            'extracted_by' => 'integer',
            'character_count' => 'integer',
            'word_count' => 'integer',
        ];
    }

    /**
     * Plaintext belongs to one document.
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * User who extracted plaintext.
     */
    public function extractor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'extracted_by');
    }
}