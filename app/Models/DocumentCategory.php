<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class DocumentCategory extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'parent_id',
        'created_by',
        'name',
        'slug',
        'description',
        'status',
        'sort_order',
    ];

    protected $casts = [
        'parent_id' => 'integer',
        'created_by' => 'integer',
        'sort_order' => 'integer',
    ];

    /**
     * Category creator.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Parent category.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(DocumentCategory::class, 'parent_id');
    }

    /**
     * Child categories.
     */
    public function children(): HasMany
    {
        return $this->hasMany(DocumentCategory::class, 'parent_id');
    }

    /**
     * Only active categories.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}