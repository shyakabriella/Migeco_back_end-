<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LaboratoryResult extends Model
{
    protected $table = 'laboratory_results';

    protected $fillable = [
        'sample_record_id',
        'laboratory',
        'lab_reference',
        'received_date',
        'test_type',
        'test_method',
        'tested_by',
        'test_date',
        'result_status',
        'test_results',
        'result_summary',
        'interpretation',
        'recommendation',
        'notes',
    ];

    protected $casts = [
        'sample_record_id' => 'integer',
        'received_date' => 'date:Y-m-d',
        'test_date' => 'date:Y-m-d',
        'test_results' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $appends = [
        'labReference',
        'receivedDate',
        'testType',
        'testMethod',
        'testedBy',
        'testDate',
        'resultStatus',
        'resultSummary',
    ];

    public function sampleRecord(): BelongsTo
    {
        return $this->belongsTo(SampleRecord::class, 'sample_record_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(LaboratoryResultDocument::class, 'laboratory_result_id');
    }

    public function getLabReferenceAttribute(): ?string
    {
        return $this->attributes['lab_reference'] ?? null;
    }

    public function getReceivedDateAttribute(): ?string
    {
        $value = $this->attributes['received_date'] ?? null;

        if (!$value) {
            return null;
        }

        try {
            return Carbon::parse($value)->format('Y-m-d');
        } catch (\Throwable $exception) {
            return (string) $value;
        }
    }

    public function getTestTypeAttribute(): ?string
    {
        return $this->attributes['test_type'] ?? null;
    }

    public function getTestMethodAttribute(): ?string
    {
        return $this->attributes['test_method'] ?? null;
    }

    public function getTestedByAttribute(): ?string
    {
        return $this->attributes['tested_by'] ?? null;
    }

    public function getTestDateAttribute(): ?string
    {
        $value = $this->attributes['test_date'] ?? null;

        if (!$value) {
            return null;
        }

        try {
            return Carbon::parse($value)->format('Y-m-d');
        } catch (\Throwable $exception) {
            return (string) $value;
        }
    }

    public function getResultStatusAttribute(): ?string
    {
        return $this->attributes['result_status'] ?? null;
    }

    public function getResultSummaryAttribute(): ?string
    {
        return $this->attributes['result_summary'] ?? null;
    }
}