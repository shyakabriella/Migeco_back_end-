<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class SampleRecord extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'sample_records';

    protected $fillable = [
        'project_id',
        'study_area_id',
        'collector_user_id',
        'created_by',
        'sample_code',
        'sample_name',
        'project_name',
        'study_area_name',
        'sample_type',
        'material',
        'collection_location',
        'province',
        'district',
        'sector',
        'cell',
        'village',
        'latitude',
        'longitude',
        'depth',
        'collection_method',
        'collected_by',
        'collected_date',
        'storage_condition',
        'chain_of_custody',
        'status',
        'notes',
        'metadata',
    ];

    protected $casts = [
        'project_id' => 'integer',
        'study_area_id' => 'integer',
        'collector_user_id' => 'integer',
        'created_by' => 'integer',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'collected_date' => 'date:Y-m-d',
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected $appends = [
        'sampleCode',
        'sampleName',
        'sampleType',
        'collectedBy',
        'collectedDate',
        'chainOfCustody',
        'resultSummary',
        'laboratory',
        'testType',
        'labReference',
        'resultDocumentsCount',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function studyArea(): BelongsTo
    {
        return $this->belongsTo(StudyArea::class, 'study_area_id');
    }

    public function collector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'collector_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function laboratoryResults(): HasMany
    {
        return $this->hasMany(LaboratoryResult::class, 'sample_record_id');
    }

    public function latestLaboratoryResult(): HasOne
    {
        return $this->hasOne(LaboratoryResult::class, 'sample_record_id')->latestOfMany();
    }

    public function resultDocuments(): HasMany
    {
        return $this->hasMany(LaboratoryResultDocument::class, 'sample_record_id');
    }

    public function getSampleCodeAttribute(): ?string
    {
        return $this->sample_code;
    }

    public function getSampleNameAttribute(): ?string
    {
        return $this->sample_name;
    }

    public function getSampleTypeAttribute(): ?string
    {
        return $this->sample_type;
    }

    public function getCollectedByAttribute(): ?string
    {
        return $this->collector?->name ?: $this->collected_by;
    }

    public function getCollectedDateAttribute(): ?string
    {
        return $this->collected_date?->format('Y-m-d');
    }

    public function getChainOfCustodyAttribute(): ?string
    {
        return $this->chain_of_custody;
    }

    public function getResultSummaryAttribute(): ?string
    {
        return $this->latestLaboratoryResult?->result_summary;
    }

    public function getLaboratoryAttribute(): ?string
    {
        return $this->latestLaboratoryResult?->laboratory;
    }

    public function getTestTypeAttribute(): ?string
    {
        return $this->latestLaboratoryResult?->test_type;
    }

    public function getLabReferenceAttribute(): ?string
    {
        return $this->latestLaboratoryResult?->lab_reference;
    }

    public function getResultDocumentsCountAttribute(): int
    {
        if ($this->relationLoaded('resultDocuments')) {
            return $this->resultDocuments->count();
        }

        return (int) $this->resultDocuments()->count();
    }
}