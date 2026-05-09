<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentAiAnalysis extends Model
{
    use HasFactory;

    protected $fillable = [
        'document_id',
        'analyzed_by',
        'provider',
        'model',
        'summary',
        'detected_language',
        'confidence_score',
        'sensitivity_level',
        'suggested_category_id',
        'suggested_document_type',
        'suggested_tags',
        'key_points',
        'detected_risks',
        'entities',
        'recommended_actions',
        'raw_response',
        'status',
        'message',
    ];

    protected function casts(): array
    {
        return [
            'document_id' => 'integer',
            'analyzed_by' => 'integer',
            'suggested_category_id' => 'integer',
            'confidence_score' => 'decimal:2',
            'suggested_tags' => 'array',
            'key_points' => 'array',
            'detected_risks' => 'array',
            'entities' => 'array',
            'recommended_actions' => 'array',
            'raw_response' => 'array',
        ];
    }

    /**
     * AI analysis belongs to one document.
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * User who analyzed the document.
     */
    public function analyzer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'analyzed_by');
    }

    /**
     * Suggested category.
     */
    public function suggestedCategory(): BelongsTo
    {
        return $this->belongsTo(DocumentCategory::class, 'suggested_category_id');
    }
}