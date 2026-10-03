<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Unit extends Model
{
    use HasFactory;

    public const TYPES = [
        'count',   // pcs, box, packet, dozen, pair
        'weight',  // kg, g, mg, ton
        'volume',  // L, ml, gal
        'length',  // m, cm, mm, ft, inch
        'area',    // sqm, sqft
        'other',
    ];

    protected $fillable = [
        'company_id',
        'name',
        'short_name',
        'unit_type',
        'allow_decimal',
        'status',
        'created_by',
    ];

    protected $casts = [
        'company_id'    => 'integer',
        'allow_decimal' => 'boolean',
        'status'        => 'integer',
        'created_by'    => 'integer',
    ];

    // ─── Relationships ──────────────────────────────────

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
            'id'            => $this->id,
            'company_id'    => $this->company_id,
            'company'       => $this->relationLoaded('company') && $this->company
                ? ['id' => $this->company->id, 'name' => $this->company->name]
                : null,
            'name'          => $this->name,
            'short_name'    => $this->short_name,
            'unit_type'     => $this->unit_type,
            'allow_decimal' => (bool) $this->allow_decimal,
            'status'        => $this->status,
            'created_by'    => $this->created_by,
            'created_at'    => $this->created_at,
            'updated_at'    => $this->updated_at,
        ];
    }
}