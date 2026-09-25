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
        'status' => 'integer',
    ];

    public function type(): BelongsTo
    {
        return $this->belongsTo(CompanyType::class, 'company_type_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function userTypes(): HasMany
    {
        return $this->hasMany(UserType::class);
    }

    public function domains(): HasMany
    {
        return $this->hasMany(CompanyDomain::class);
    }
}