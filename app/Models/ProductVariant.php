<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductVariant extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'sku',
        'barcode',
        'variant_name',
        'purchase_price',
        'selling_price',
        'mrp',
        'wholesale_price',
        'weight',
        'image',
        'track_stock',
        'track_serial',
        'status',
    ];

    protected $casts = [
        'product_id'      => 'integer',
        'purchase_price'  => 'float',
        'selling_price'   => 'float',
        'mrp'             => 'float',
        'wholesale_price' => 'float',
        'weight'          => 'float',
        'track_stock'     => 'boolean',
        'track_serial'    => 'boolean',
        'status'          => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function values(): HasMany
    {
        return $this->hasMany(ProductVariantValue::class, 'variant_id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class, 'variant_id');
    }

    public function barcodes(): HasMany
    {
        return $this->hasMany(Barcode::class, 'variant_id');
    }

    public function toApiArray(bool $withValues = false): array
    {
        $data = [
            'id'              => $this->id,
            'product_id'      => $this->product_id,
            'sku'             => $this->sku,
            'barcode'         => $this->barcode,
            'variant_name'    => $this->variant_name,
            'purchase_price'  => (float) $this->purchase_price,
            'selling_price'   => (float) $this->selling_price,
            'mrp'             => (float) $this->mrp,
            'wholesale_price' => (float) $this->wholesale_price,
            'weight'          => $this->weight,
            'image'           => $this->image,
            'image_url'       => $this->image ? asset('storage/' . $this->image) : null,
            'track_stock'     => (bool) $this->track_stock,
            'track_serial'    => (bool) $this->track_serial,
            'status'          => $this->status,
            'created_at'      => $this->created_at,
            'updated_at'      => $this->updated_at,
        ];

        if ($withValues) {
            $data['values'] = $this->relationLoaded('values')
                ? $this->values->map(fn ($v) => $v->toApiArray())->values()
                : [];
        }

        return $data;
    }
}