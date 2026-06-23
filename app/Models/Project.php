<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Project extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'created_by',
        'project_manager_id',
        'name',
        'code',
        'slug',
        'description',
        'location_name',
        'latitude',
        'longitude',
        'project_type',
        'status',
        'security_level',
        'start_date',
        'end_date',
        'metadata',
        'archived_by',
        'archived_at',
        'archive_reason',
        'restored_by',
        'restored_at',
        'restore_reason',
    ];

    protected $casts = [
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'start_date' => 'date',
        'end_date' => 'date',
        'metadata' => 'array',
        'archived_at' => 'datetime',
        'restored_at' => 'datetime',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'project_manager_id');
    }

    public function archiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by');
    }

    public function restorer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'restored_by');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'project_id');
    }

    public function activeDocuments(): HasMany
    {
        return $this->hasMany(Document::class, 'project_id')
            ->where('status', '!=', 'archived');
    }

    public function archivedDocuments(): HasMany
    {
        return $this->hasMany(Document::class, 'project_id')
            ->where('status', 'archived');
    }

    public function securityAlertDocuments(): HasMany
    {
        return $this->hasMany(Document::class, 'project_id')
            ->where(function (Builder $query) {
                $query
                    ->whereIn('status', [
                        'quarantined',
                        'suspicious',
                        'infected',
                        'rejected',
                    ])
                    ->orWhereIn('scan_status', [
                        'suspicious',
                        'infected',
                        'failed',
                    ])
                    ->orWhereIn('sandbox_status', [
                        'unsafe',
                        'failed',
                    ]);
            });
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeNotArchived(Builder $query): Builder
    {
        return $query->where('status', '!=', 'archived');
    }

    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if (!$user) {
            return $query->whereRaw('1 = 0');
        }

        $roleSlug = $user->role?->slug;

        if (
            (method_exists($user, 'isAdmin') && $user->isAdmin())
            || $roleSlug === 'admin'
        ) {
            return $query;
        }

        if ($roleSlug === 'viewer') {
            return $query
                ->whereIn('security_level', ['public', 'internal'])
                ->where('status', '!=', 'archived');
        }

        if (!in_array($roleSlug, ['project_manager', 'document_controller', 'security_officer'], true)) {
            $query->where('security_level', '!=', 'restricted');
        }

        return $query;
    }
}