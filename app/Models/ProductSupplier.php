<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductSupplier extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'variant_id',
        'supplier_id',
        'supplier_sku',
        'purchase_price',
        'minimum_order_qty',
        'is_preferred',
        'status',
    ];

    protected $casts = [
        'product_id'        => 'integer',
        'variant_id'        => 'integer',
        'supplier_id'       => 'integer',
        'purchase_price'    => 'float',
        'minimum_order_qty' => 'integer',
        'is_preferred'      => 'boolean',
        'status'            => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function toApiArray(): array
    {
        return [
            'id'                => $this->id,
            'product_id'        => $this->product_id,
            'product'           => $this->relationLoaded('product') && $this->product
                ? ['id' => $this->product->id, 'name' => $this->product->name]
                : null,
            'variant_id'        => $this->variant_id,
            'variant'           => $this->relationLoaded('variant') && $this->variant
                ? ['id' => $this->variant->id, 'variant_name' => $this->variant->variant_name]
                : null,
            'supplier_id'       => $this->supplier_id,
            'supplier'          => $this->relationLoaded('supplier') && $this->supplier
                ? ['id' => $this->supplier->id, 'name' => $this->supplier->name]
                : null,
            'supplier_sku'      => $this->supplier_sku,
            'purchase_price'    => (float) $this->purchase_price,
            'minimum_order_qty' => (int) $this->minimum_order_qty,
            'is_preferred'      => (bool) $this->is_preferred,
            'status'            => $this->status,
            'created_at'        => $this->created_at,
            'updated_at'        => $this->updated_at,
        ];
    }
}