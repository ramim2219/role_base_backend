<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'logo',
        'company_type_id',
        'slug',
        'status',
        'address',
        'contact',
        'email',
        'created_by',
    ];

    protected $casts = [
        'company_type_id' => 'integer',
        'status'          => 'integer',
        'created_by'      => 'integer',
    ];

    public function companyType(): BelongsTo
    {
        return $this->belongsTo(CompanyType::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function toApiArray(): array
    {
        return [
            'id'              => $this->id,
            'name'            => $this->name,
            'logo'            => $this->logo,
            'company_type_id' => $this->company_type_id,
            'company_type'    => $this->relationLoaded('companyType') && $this->companyType
                ? ['id' => $this->companyType->id, 'name' => $this->companyType->name]
                : null,
            'slug'            => $this->slug,
            'status'          => $this->status,
            'address'         => $this->address,
            'contact'         => $this->contact,
            'email'           => $this->email,
            'created_by'      => $this->created_by,
            'created_at'      => $this->created_at,
            'updated_at'      => $this->updated_at,
        ];
    }
}