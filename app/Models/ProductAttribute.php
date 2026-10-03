<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductAttribute extends Model
{
    use HasFactory;

    protected $fillable = ['product_id', 'attribute_id', 'is_required'];

    protected $casts = [
        'product_id'   => 'integer',
        'attribute_id' => 'integer',
        'is_required'  => 'boolean',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function attribute(): BelongsTo
    {
        return $this->belongsTo(Attribute::class);
    }

    public function toApiArray(): array
    {
        return [
            'id'           => $this->id,
            'product_id'   => $this->product_id,
            'attribute_id' => $this->attribute_id,
            'attribute'    => $this->relationLoaded('attribute') && $this->attribute
                ? [
                    'id'           => $this->attribute->id,
                    'name'         => $this->attribute->name,
                    'display_name' => $this->attribute->display_name,
                    'input_type'   => $this->attribute->input_type,
                    'values'       => $this->attribute->relationLoaded('values')
                        ? $this->attribute->values->map(fn ($v) => [
                            'id'           => $v->id,
                            'value'        => $v->value,
                            'display_name' => $v->display_name,
                        ])->values()
                        : [],
                ]
                : null,
            'is_required'  => (bool) $this->is_required,
            'created_at'   => $this->created_at,
            'updated_at'   => $this->updated_at,
        ];
    }
}