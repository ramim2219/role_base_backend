<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Stock extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'warehouse_id',
        'product_id',
        'variant_id',
        'quantity',
        'reserved_quantity',
        'available_quantity',
        'reorder_level',
        'reorder_quantity',
    ];

    protected $casts = [
        'company_id'         => 'integer',
        'warehouse_id'       => 'integer',
        'product_id'         => 'integer',
        'variant_id'         => 'integer',
        'quantity'           => 'float',
        'reserved_quantity'  => 'float',
        'available_quantity' => 'float',
        'reorder_level'      => 'float',
        'reorder_quantity'   => 'float',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function toApiArray(): array
    {
        return [
            'id'                 => $this->id,
            'company_id'         => $this->company_id,
            'warehouse_id'       => $this->warehouse_id,
            'warehouse'          => $this->relationLoaded('warehouse') && $this->warehouse
                ? [
                    'id'   => $this->warehouse->id,
                    'name' => $this->warehouse->name,
                    'code' => $this->warehouse->code,
                ]
                : null,
            'product_id'         => $this->product_id,
            'product'            => $this->relationLoaded('product') && $this->product
                ? ['id' => $this->product->id, 'name' => $this->product->name]
                : null,
            'variant_id'         => $this->variant_id,
            'variant'            => $this->relationLoaded('variant') && $this->variant
                ? ['id' => $this->variant->id, 'variant_name' => $this->variant->variant_name]
                : null,
            'quantity'           => (float) $this->quantity,
            'reserved_quantity'  => (float) $this->reserved_quantity,
            'available_quantity' => (float) $this->available_quantity,
            'reorder_level'      => (float) $this->reorder_level,
            'reorder_quantity'   => (float) $this->reorder_quantity,
            'updated_at'         => $this->updated_at,
        ];
    }
}