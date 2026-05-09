<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Document extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        /*
        |--------------------------------------------------------------------------
        | Relations
        |--------------------------------------------------------------------------
        */
        'project_id',
        'document_category_id',
        'uploaded_by',
        'approved_by',

        /*
        |--------------------------------------------------------------------------
        | Document Basic Information
        |--------------------------------------------------------------------------
        */
        'document_code',
        'title',
        'slug',
        'description',
        'document_type',

        /*
        |--------------------------------------------------------------------------
        | File Information
        |--------------------------------------------------------------------------
        */
        'original_file_name',
        'stored_file_name',
        'file_path',
        'original_clean_file_path',
        'encrypted_file_path',
        'disk',
        'mime_type',
        'extension',
        'file_size',
        'sha256_hash',

        /*
        |--------------------------------------------------------------------------
        | Version / Security
        |--------------------------------------------------------------------------
        */
        'version_number',
        'security_level',

        /*
        |--------------------------------------------------------------------------
        | Antivirus / Scan
        |--------------------------------------------------------------------------
        */
        'status',
        'scan_status',
        'scan_message',
        'scanned_at',

        /*
        |--------------------------------------------------------------------------
        | Cryptography / Encryption
        |--------------------------------------------------------------------------
        */
        'encryption_status',
        'encryption_algorithm',
        'encryption_key_id',
        'encrypted_file_size',
        'encrypted_sha256_hash',
        'encrypted_at',

        /*
        |--------------------------------------------------------------------------
        | Plaintext Extraction
        |--------------------------------------------------------------------------
        */
        'plaintext_status',
        'plaintext_file_path',
        'plaintext_extracted_by',
        'plaintext_character_count',
        'plaintext_word_count',
        'plaintext_sha256_hash',
        'plaintext_preview',
        'plaintext_extracted_at',

        /*
        |--------------------------------------------------------------------------
        | Sandbox
        |--------------------------------------------------------------------------
        */
        'sandbox_status',
        'sandbox_tested_by',
        'sandbox_score',
        'sandbox_message',
        'sandbox_report',
        'sandbox_tested_at',

        /*
        |--------------------------------------------------------------------------
        | AI Analysis
        |--------------------------------------------------------------------------
        */
        'ai_status',
        'ai_analyzed_by',
        'ai_provider',
        'ai_model',
        'ai_summary',
        'ai_detected_language',
        'ai_confidence_score',
        'ai_sensitivity_level',
        'ai_suggested_category_id',
        'ai_suggested_document_type',
        'ai_suggested_tags',
        'ai_key_points',
        'ai_detected_risks',
        'ai_entities',
        'ai_recommended_actions',
        'ai_analyzed_at',

        /*
        |--------------------------------------------------------------------------
        | Extra Data
        |--------------------------------------------------------------------------
        */
        'tags',
        'metadata',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            /*
            |--------------------------------------------------------------------------
            | Relations
            |--------------------------------------------------------------------------
            */
            'project_id' => 'integer',
            'document_category_id' => 'integer',
            'uploaded_by' => 'integer',
            'approved_by' => 'integer',

            /*
            |--------------------------------------------------------------------------
            | File / Version
            |--------------------------------------------------------------------------
            */
            'file_size' => 'integer',
            'encrypted_file_size' => 'integer',
            'version_number' => 'integer',

            /*
            |--------------------------------------------------------------------------
            | Plaintext
            |--------------------------------------------------------------------------
            */
            'plaintext_extracted_by' => 'integer',
            'plaintext_character_count' => 'integer',
            'plaintext_word_count' => 'integer',

            /*
            |--------------------------------------------------------------------------
            | Sandbox
            |--------------------------------------------------------------------------
            */
            'sandbox_tested_by' => 'integer',
            'sandbox_score' => 'integer',
            'sandbox_report' => 'array',

            /*
            |--------------------------------------------------------------------------
            | AI Analysis
            |--------------------------------------------------------------------------
            */
            'ai_analyzed_by' => 'integer',
            'ai_suggested_category_id' => 'integer',
            'ai_confidence_score' => 'decimal:2',
            'ai_suggested_tags' => 'array',
            'ai_key_points' => 'array',
            'ai_detected_risks' => 'array',
            'ai_entities' => 'array',
            'ai_recommended_actions' => 'array',

            /*
            |--------------------------------------------------------------------------
            | JSON Fields
            |--------------------------------------------------------------------------
            */
            'tags' => 'array',
            'metadata' => 'array',

            /*
            |--------------------------------------------------------------------------
            | Date Fields
            |--------------------------------------------------------------------------
            */
            'scanned_at' => 'datetime',
            'encrypted_at' => 'datetime',
            'plaintext_extracted_at' => 'datetime',
            'sandbox_tested_at' => 'datetime',
            'ai_analyzed_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(DocumentCategory::class, 'document_category_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function scanLogs(): HasMany
    {
        return $this->hasMany(DocumentScanLog::class);
    }

    public function encryptionLogs(): HasMany
    {
        return $this->hasMany(DocumentEncryptionLog::class);
    }

    public function plaintext(): HasOne
    {
        return $this->hasOne(DocumentPlaintext::class);
    }

    public function plaintextLogs(): HasMany
    {
        return $this->hasMany(DocumentPlaintextLog::class);
    }

    public function plaintextExtractor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'plaintext_extracted_by');
    }

    public function sandboxLogs(): HasMany
    {
        return $this->hasMany(DocumentSandboxLog::class);
    }

    public function sandboxTester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sandbox_tested_by');
    }

    public function aiAnalysis(): HasOne
    {
        return $this->hasOne(DocumentAiAnalysis::class);
    }

    public function aiAnalysisLogs(): HasMany
    {
        return $this->hasMany(DocumentAiAnalysisLog::class);
    }

    public function aiAnalyzer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ai_analyzed_by');
    }

    public function aiSuggestedCategory(): BelongsTo
    {
        return $this->belongsTo(DocumentCategory::class, 'ai_suggested_category_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Query Scopes
    |--------------------------------------------------------------------------
    */

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopePendingScan($query)
    {
        return $query->where('scan_status', 'pending');
    }

    public function scopeClean($query)
    {
        return $query->where('scan_status', 'clean');
    }

    public function scopeInfected($query)
    {
        return $query->where('scan_status', 'infected');
    }

    public function scopeQuarantined($query)
    {
        return $query->where('status', 'quarantined');
    }

    public function scopeRestricted($query)
    {
        return $query->where('security_level', 'restricted');
    }

    public function scopeEncrypted($query)
    {
        return $query->where('encryption_status', 'encrypted');
    }

    public function scopeNotEncrypted($query)
    {
        return $query->where('encryption_status', 'not_encrypted');
    }

    public function scopePlaintextExtracted($query)
    {
        return $query->where('plaintext_status', 'extracted');
    }

    public function scopePlaintextNotExtracted($query)
    {
        return $query->where('plaintext_status', 'not_extracted');
    }

    public function scopeSandboxSafe($query)
    {
        return $query->where('sandbox_status', 'safe');
    }

    public function scopeSandboxUnsafe($query)
    {
        return $query->where('sandbox_status', 'unsafe');
    }

    public function scopeSandboxNotTested($query)
    {
        return $query->where('sandbox_status', 'not_tested');
    }

    public function scopeAiAnalyzed($query)
    {
        return $query->where('ai_status', 'analyzed');
    }

    public function scopeAiNotAnalyzed($query)
    {
        return $query->where('ai_status', 'not_analyzed');
    }

    public function scopeAiFailed($query)
    {
        return $query->where('ai_status', 'failed');
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    public function isSafeToOpen(): bool
    {
        $requiresSandbox = (bool) config('dms.sandbox.require_safe_sandbox_for_access', true);

        if (!$requiresSandbox) {
            return $this->status === 'active'
                && $this->scan_status === 'clean'
                && $this->sandbox_status !== 'unsafe';
        }

        return $this->status === 'active'
            && $this->scan_status === 'clean'
            && $this->sandbox_status === 'safe';
    }

    public function isInfected(): bool
    {
        return $this->scan_status === 'infected'
            || $this->status === 'quarantined';
    }

    public function isPendingScan(): bool
    {
        return $this->status === 'pending_scan'
            || $this->scan_status === 'pending';
    }

    public function isQuarantined(): bool
    {
        return $this->status === 'quarantined';
    }

    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    public function isEncrypted(): bool
    {
        return $this->encryption_status === 'encrypted';
    }

    public function hasPlaintextExtracted(): bool
    {
        return $this->plaintext_status === 'extracted';
    }

    public function isPlaintextPending(): bool
    {
        return $this->plaintext_status === 'pending';
    }

    public function isPlaintextFailed(): bool
    {
        return $this->plaintext_status === 'failed';
    }

    public function isSandboxSafe(): bool
    {
        return $this->sandbox_status === 'safe';
    }

    public function isSandboxUnsafe(): bool
    {
        return $this->sandbox_status === 'unsafe';
    }

    public function isSandboxPending(): bool
    {
        return $this->sandbox_status === 'pending';
    }

    public function isSandboxNotTested(): bool
    {
        return $this->sandbox_status === 'not_tested';
    }

    public function isSandboxFailed(): bool
    {
        return $this->sandbox_status === 'failed';
    }

    public function isAiAnalyzed(): bool
    {
        return $this->ai_status === 'analyzed';
    }

    public function isAiFailed(): bool
    {
        return $this->ai_status === 'failed';
    }

    public function isAiPending(): bool
    {
        return $this->ai_status === 'pending';
    }

    public function isAiNotAnalyzed(): bool
    {
        return $this->ai_status === 'not_analyzed';
    }

    /*
    |--------------------------------------------------------------------------
    | Accessors
    |--------------------------------------------------------------------------
    */

    public function getReadableFileSizeAttribute(): string
    {
        $bytes = (int) $this->file_size;

        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        }

        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        }

        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        }

        return $bytes . ' bytes';
    }

    public function getReadableEncryptedFileSizeAttribute(): string
    {
        $bytes = (int) ($this->encrypted_file_size ?? 0);

        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        }

        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        }

        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        }

        return $bytes . ' bytes';
    }

    public function getReadablePlaintextWordCountAttribute(): string
    {
        $words = (int) ($this->plaintext_word_count ?? 0);

        return number_format($words) . ' words';
    }

    public function getReadablePlaintextCharacterCountAttribute(): string
    {
        $characters = (int) ($this->plaintext_character_count ?? 0);

        return number_format($characters) . ' characters';
    }
}