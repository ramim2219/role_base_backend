<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MenuAllocation extends Model
{
    use HasFactory;

    protected $fillable = [
        'menu_info_id',
        'user_info_id',
        'user_type_id',
        'priority',
        'status',
        'created_by',
    ];

    protected $casts = [
        'menu_info_id' => 'integer',
        'user_info_id' => 'integer',   // NULL stays NULL
        'user_type_id' => 'integer',
        'priority'     => 'integer',
        'created_by'   => 'integer',
    ];

    // ─────────────────────────────────────────────────────
    // Relationships
    // ─────────────────────────────────────────────────────

    public function menu(): BelongsTo
    {
        return $this->belongsTo(MenuInfo::class, 'menu_info_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_info_id');
    }

    public function userType(): BelongsTo
    {
        return $this->belongsTo(UserType::class, 'user_type_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ─────────────────────────────────────────────────────
    // Scopes
    // ─────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_info_id', $userId);
    }

    public function scopeForUserType($query, int $typeId)
    {
        return $query->where('user_type_id', $typeId);
    }

    // ─────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────

    /**
     * Frontend expects 0 not NULL for the unused scope field.
     */
    public function toApiArray(): array
    {
        return [
            'id'             => $this->id,
            'menu_info_id'   => $this->menu_info_id,
            'tbl_menuinfo_id'=> $this->menu_info_id,          // alias for old frontend
            'user_info_id'   => $this->user_info_id ?? 0,     // ✅ NULL → 0
            'user_type_id'   => $this->user_type_id ?? 0,     // ✅ NULL → 0
            'tbl_userinfo_id'=> $this->user_info_id ?? 0,     // alias
            'tbl_type_id'    => $this->user_type_id ?? 0,     // alias
            'priority'       => $this->priority,
            'status'         => $this->status,
            'created_at'     => $this->created_at,
        ];
    }
}