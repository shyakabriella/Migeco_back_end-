<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class LaboratoryResult extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'sample_record_id',
        'created_by',
        'laboratory',
        'lab_reference',
        'received_date',
        'test_type',
        'test_method',
        'tested_by',
        'test_date',
        'result_status',
        'result_summary',
        'test_results',
        'interpretation',
        'recommendation',
        'notes',
        'metadata',
    ];

    protected $casts = [
        'sample_record_id' => 'integer',
        'created_by' => 'integer',
        'received_date' => 'date:Y-m-d',
        'test_date' => 'date:Y-m-d',
        'test_results' => 'array',
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected $appends = [
        'labReference',
        'testType',
        'testMethod',
        'testedBy',
        'testDate',
        'resultStatus',
        'resultSummary',
        'resultDocuments',
    ];

    public function sample(): BelongsTo
    {
        return $this->belongsTo(SampleRecord::class, 'sample_record_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(LaboratoryResultDocument::class, 'laboratory_result_id');
    }

    public function getLabReferenceAttribute(): ?string
    {
        return $this->lab_reference;
    }

    public function getTestTypeAttribute(): ?string
    {
        return $this->test_type;
    }

    public function getTestMethodAttribute(): ?string
    {
        return $this->test_method;
    }

    public function getTestedByAttribute(): ?string
    {
        return $this->tested_by;
    }

    public function getTestDateAttribute(): ?string
    {
        return $this->test_date?->format('Y-m-d');
    }

    public function getResultStatusAttribute(): ?string
    {
        return $this->result_status;
    }

    public function getResultSummaryAttribute(): ?string
    {
        return $this->result_summary;
    }

    public function getResultDocumentsAttribute()
    {
        if ($this->relationLoaded('documents')) {
            return $this->documents;
        }

        return $this->documents()->get();
    }
}