<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Brand extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'category_id',
        'company_id',
        'logo',
        'status',
        'created_by',
    ];

    protected $casts = [
        'category_id' => 'integer',
        'company_id'  => 'integer',
        'status'      => 'integer',
        'created_by'  => 'integer',
    ];

    // ─── Relationships ──────────────────────────────────

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
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

    // ─── API shape ──────────────────────────────────────

    public function toApiArray(): array
    {
        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'category_id' => $this->category_id,
            'category'    => $this->relationLoaded('category') && $this->category
                ? [
                    'id'         => $this->category->id,
                    'name'       => $this->category->name,
                    'company_id' => $this->category->company_id,
                ]
                : null,
            'company_id'  => $this->company_id,
            'company'     => $this->relationLoaded('company') && $this->company
                ? ['id' => $this->company->id, 'name' => $this->company->name]
                : null,
            'logo'        => $this->logo,
            'logo_url'    => $this->logo ? asset('storage/' . $this->logo) : null,
            'status'      => $this->status,
            'created_by'  => $this->created_by,
            'created_at'  => $this->created_at,
            'updated_at'  => $this->updated_at,
        ];
    }
}