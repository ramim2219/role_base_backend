<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Attribute extends Model
{
    use HasFactory;

    public const INPUT_TYPES = [
        'text',
        'number',
        'select',
        'multiselect',
        'color',
        'boolean',
    ];

    protected $fillable = [
        'company_id',
        'name',
        'display_name',
        'input_type',
        'is_variant_attribute',
        'status',
        'created_by',
    ];

    protected $casts = [
        'company_id'            => 'integer',
        'is_variant_attribute'  => 'boolean',
        'status'                => 'integer',
        'created_by'            => 'integer',
    ];

    // ─── Relationships ──────────────────────────────────

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function values(): HasMany
    {
        return $this->hasMany(AttributeValue::class)->orderBy('sort_order')->orderBy('id');
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

    public function scopeVariant($query)
    {
        return $query->where('is_variant_attribute', true);
    }

    // ─── API shape ──────────────────────────────────────

    public function toApiArray(bool $withValues = false): array
    {
        $data = [
            'id'                    => $this->id,
            'company_id'            => $this->company_id,
            'company'               => $this->relationLoaded('company') && $this->company
                ? ['id' => $this->company->id, 'name' => $this->company->name]
                : null,
            'name'                  => $this->name,
            'display_name'          => $this->display_name,
            'input_type'            => $this->input_type,
            'is_variant_attribute'  => (bool) $this->is_variant_attribute,
            'status'                => $this->status,
            'created_by'            => $this->created_by,
            'created_at'            => $this->created_at,
            'updated_at'            => $this->updated_at,
        ];

        if ($withValues) {
            $data['values'] = $this->relationLoaded('values')
                ? $this->values->map(fn ($v) => $v->toApiArray())->values()
                : [];
        }

        return $data;
    }
}