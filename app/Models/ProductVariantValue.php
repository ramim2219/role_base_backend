<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductVariantValue extends Model
{
    use HasFactory;

    protected $fillable = [
        'variant_id',
        'attribute_id',
        'attribute_value_id',
    ];

    protected $casts = [
        'variant_id'         => 'integer',
        'attribute_id'       => 'integer',
        'attribute_value_id' => 'integer',
    ];

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function attribute(): BelongsTo
    {
        return $this->belongsTo(Attribute::class);
    }

    public function attributeValue(): BelongsTo
    {
        return $this->belongsTo(AttributeValue::class, 'attribute_value_id');
    }

    public function toApiArray(): array
    {
        return [
            'id'                 => $this->id,
            'variant_id'         => $this->variant_id,
            'attribute_id'       => $this->attribute_id,
            'attribute'          => $this->relationLoaded('attribute') && $this->attribute
                ? [
                    'id'           => $this->attribute->id,
                    'name'         => $this->attribute->name,
                    'display_name' => $this->attribute->display_name,
                ]
                : null,
            'attribute_value_id' => $this->attribute_value_id,
            'attribute_value'    => $this->relationLoaded('attributeValue') && $this->attributeValue
                ? [
                    'id'           => $this->attributeValue->id,
                    'value'        => $this->attributeValue->value,
                    'display_name' => $this->attributeValue->display_name,
                ]
                : null,
        ];
    }
}