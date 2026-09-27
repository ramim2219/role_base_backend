<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyType extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'created_by',
    ];

    protected $casts = [
        'created_by' => 'integer',
    ];

    // ─── Relationships ─────────────────────────

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ─── Helpers ───────────────────────────────

    public function toApiArray(): array
    {
        return [
            'id'         => $this->id,
            'name'       => $this->name,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}