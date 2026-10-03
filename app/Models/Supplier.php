<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'name',
        'company_name',
        'phone',
        'email',
        'address',
        'tax_number',
        'opening_balance',
        'credit_limit',
        'status',
        'created_by',
    ];

    protected $casts = [
        'company_id'      => 'integer',
        'opening_balance' => 'float',
        'credit_limit'    => 'float',
        'status'          => 'integer',
        'created_by'      => 'integer',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(ProductSupplier::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 1);
    }

    public function toApiArray(): array
    {
        return [
            'id'              => $this->id,
            'company_id'      => $this->company_id,
            'name'            => $this->name,
            'company_name'    => $this->company_name,
            'phone'           => $this->phone,
            'email'           => $this->email,
            'address'         => $this->address,
            'tax_number'      => $this->tax_number,
            'opening_balance' => (float) $this->opening_balance,
            'credit_limit'    => $this->credit_limit !== null ? (float) $this->credit_limit : null,
            'status'          => $this->status,
            'created_by'      => $this->created_by,
            'created_at'      => $this->created_at,
            'updated_at'      => $this->updated_at,
        ];
    }
}