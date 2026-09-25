<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MenuInfo extends Model
{
    use HasFactory;

    // ─────────────────────────────────────────────────────
    // Table + columns
    // ─────────────────────────────────────────────────────

    // protected $table = 'menu_infos'; // Laravel auto-resolves this — no need

    protected $fillable = [
        'menu',
        'icon',
        'menu_url',
        'parent_id',
        'type',
        'status',
        'menu_order',
        'created_by',
    ];

    protected $casts = [
        'parent_id'  => 'integer',   // cast NULL → 0? No — cast keeps NULL
        'menu_order' => 'integer',
        'created_by' => 'integer',
    ];

    // ─────────────────────────────────────────────────────
    // Relationships
    // ─────────────────────────────────────────────────────

    /** Who created this menu */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Parent menu (null = top-level) */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(MenuInfo::class, 'parent_id');
    }

    /** All direct children (submenus + access) */
    public function children(): HasMany
    {
        return $this->hasMany(MenuInfo::class, 'parent_id')
                    ->orderBy('menu_order');
    }

    /** Only submenus (type = Menu) */
    public function submenus(): HasMany
    {
        return $this->hasMany(MenuInfo::class, 'parent_id')
                    ->where('type', 'Menu')
                    ->orderBy('menu_order');
    }

    /** Only access items (type = Access) */
    public function accesses(): HasMany
    {
        return $this->hasMany(MenuInfo::class, 'parent_id')
                    ->where('type', 'Access')
                    ->orderBy('menu_order');
    }

    /** Allocations pointing at this menu */
    public function allocations(): HasMany
    {
        return $this->hasMany(MenuAllocation::class, 'menu_info_id');
    }

    // ─────────────────────────────────────────────────────
    // Scopes
    // ─────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('status', 'On');
    }

    /**
     * Top-level menus (parent_id IS NULL).
     * Note: DB stores NULL — no more magic 0.
     */
    public function scopeTopLevel($query)
    {
        return $query->whereNull('parent_id');
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    // ─────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────

    /**
     * Convert the model to an array shaped for the frontend.
     * Turns parent_id NULL → 0 so the React code keeps working.
     */
    public function toApiArray(): array
    {
        return [
            'id'         => $this->id,
            'menu'       => $this->menu,
            'icon'       => $this->icon,
            'menu_url'   => $this->menu_url,
            'parent_id'  => $this->parent_id ?? 0,   // ✅ NULL → 0
            'type'       => $this->type,
            'status'     => $this->status,
            'menu_order' => $this->menu_order,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}