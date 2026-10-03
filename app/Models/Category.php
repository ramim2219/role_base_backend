<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Category extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'parent_id',
        'name',
        'description',
        'image',
        'status',
        'created_by',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'parent_id'  => 'integer',
        'status'     => 'integer',
        'created_by' => 'integer',
    ];

    // ─── Relationships ──────────────────────────────────

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id')->orderBy('name');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ─── Scopes ─────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('status', 1);
    }

    public function scopeTopLevel($query)
    {
        return $query->whereNull('parent_id');
    }

    // ─── API shape ──────────────────────────────────────

    public function toApiArray(): array
    {
        return [
            'id'          => $this->id,
            'company_id'  => $this->company_id,
            'company'     => $this->relationLoaded('company') && $this->company
                ? ['id' => $this->company->id, 'name' => $this->company->name]
                : null,
            'parent_id'   => $this->parent_id,
            'parent'      => $this->relationLoaded('parent') && $this->parent
                ? ['id' => $this->parent->id, 'name' => $this->parent->name]
                : null,
            'name'        => $this->name,
            'description' => $this->description,
            'image'       => $this->image,
            'image_url'   => $this->image ? asset('storage/' . $this->image) : null,
            'status'      => $this->status,
            'created_by'  => $this->created_by,
            'created_at'  => $this->created_at,
            'updated_at'  => $this->updated_at,
        ];
    }
}