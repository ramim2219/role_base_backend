<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Warehouse extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'name',
        'code',
        'address',
        'phone',
        'manager_id',
        'status',
        'created_by',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'manager_id' => 'integer',
        'status'     => 'integer',
        'created_by' => 'integer',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(Stock::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 1);
    }

    public function toApiArray(): array
    {
        return [
            'id'          => $this->id,
            'company_id'  => $this->company_id,
            'name'        => $this->name,
            'code'        => $this->code,
            'address'     => $this->address,
            'phone'       => $this->phone,
            'manager_id'  => $this->manager_id,
            'manager'     => $this->relationLoaded('manager') && $this->manager
                ? ['id' => $this->manager->id, 'name' => $this->manager->name]
                : null,
            'status'      => $this->status,
            'created_by'  => $this->created_by,
            'created_at'  => $this->created_at,
            'updated_at'  => $this->updated_at,
        ];
    }
}