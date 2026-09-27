<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UserType extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'company_id',
        'created_by',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'created_by' => 'integer',
    ];

    // ─── Relationships ─────────────────────────

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    // ─── Scopes ────────────────────────────────

    /** Global types (not tied to any company) */
    public function scopeGlobal($query)
    {
        return $query->whereNull('company_id');
    }

    /** Types belonging to a specific company */
    public function scopeForCompany($query, int $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    // ─── API shape ─────────────────────────────

    public function toApiArray(): array
    {
        return [
            'id'         => $this->id,
            'name'       => $this->name,
            'company_id' => $this->company_id,
            'company'    => $this->relationLoaded('company') && $this->company
                ? ['id' => $this->company->id, 'name' => $this->company->name]
                : null,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}