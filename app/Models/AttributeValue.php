<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttributeValue extends Model
{
    use HasFactory;

    protected $fillable = [
        'attribute_id',
        'value',
        'display_name',
        'sort_order',
        'status',
    ];

    protected $casts = [
        'attribute_id' => 'integer',
        'sort_order'   => 'integer',
        'status'       => 'integer',
    ];

    // ─── Relationships ──────────────────────────────────

    public function attribute(): BelongsTo
    {
        return $this->belongsTo(Attribute::class);
    }

    // ─── API shape ──────────────────────────────────────

    public function toApiArray(): array
    {
        return [
            'id'           => $this->id,
            'attribute_id' => $this->attribute_id,
            'value'        => $this->value,
            'display_name' => $this->display_name,
            'sort_order'   => $this->sort_order,
            'status'       => $this->status,
            'created_at'   => $this->created_at,
            'updated_at'   => $this->updated_at,
        ];
    }
}