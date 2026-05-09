<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentEncryptionLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'document_id',
        'performed_by',
        'action',
        'status',
        'algorithm',
        'key_id',
        'source_file_path',
        'encrypted_file_path',
        'source_sha256_hash',
        'encrypted_sha256_hash',
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
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * Encryption log belongs to document.
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * User who performed encryption/decryption action.
     */
    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}