<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMovement extends Model
{
    use HasFactory;

    public const TYPES = [
        'purchase',
        'sale',
        'sale_return',
        'purchase_return',
        'transfer_in',
        'transfer_out',
        'adjustment',
        'damage',
        'expired',
        'opening_stock',
    ];

    protected $fillable = [
        'company_id',
        'warehouse_id',
        'product_id',
        'variant_id',
        'movement_type',
        'reference_type',
        'reference_id',
        'quantity',
        'previous_quantity',
        'new_quantity',
        'note',
        'created_by',
    ];

    protected $casts = [
        'company_id'        => 'integer',
        'warehouse_id'      => 'integer',
        'product_id'        => 'integer',
        'variant_id'        => 'integer',
        'reference_id'      => 'integer',
        'quantity'          => 'float',
        'previous_quantity' => 'float',
        'new_quantity'      => 'float',
        'created_by'        => 'integer',
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function toApiArray(): array
    {
        return [
            'id'                => $this->id,
            'company_id'        => $this->company_id,
            'warehouse_id'      => $this->warehouse_id,
            'warehouse'         => $this->relationLoaded('warehouse') && $this->warehouse
                ? ['id' => $this->warehouse->id, 'name' => $this->warehouse->name]
                : null,
            'product_id'        => $this->product_id,
            'product'           => $this->relationLoaded('product') && $this->product
                ? ['id' => $this->product->id, 'name' => $this->product->name]
                : null,
            'variant_id'        => $this->variant_id,
            'variant'           => $this->relationLoaded('variant') && $this->variant
                ? ['id' => $this->variant->id, 'variant_name' => $this->variant->variant_name]
                : null,
            'movement_type'     => $this->movement_type,
            'reference_type'    => $this->reference_type,
            'reference_id'      => $this->reference_id,
            'quantity'          => (float) $this->quantity,
            'previous_quantity' => (float) $this->previous_quantity,
            'new_quantity'      => (float) $this->new_quantity,
            'note'              => $this->note,
            'created_by'        => $this->created_by,
            'created_at'        => $this->created_at,
        ];
    }
}