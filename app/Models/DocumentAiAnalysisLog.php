<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentAiAnalysisLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'document_id',
        'performed_by',
        'analysis_type',
        'status',
        'provider',
        'model',
        'input_character_count',
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
            'input_character_count' => 'integer',
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
     * User who performed AI analysis.
     */
    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}