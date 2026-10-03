<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductImage extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'variant_id',
        'image',
        'is_primary',
        'sort_order',
    ];

    protected $casts = [
        'product_id'  => 'integer',
        'variant_id'  => 'integer',
        'is_primary'  => 'boolean',
        'sort_order'  => 'integer',
    ];

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
            'id'          => $this->id,
            'product_id'  => $this->product_id,
            'variant_id'  => $this->variant_id,
            'image'       => $this->image,
            'image_url'   => $this->image ? asset('storage/' . $this->image) : null,
            'is_primary'  => (bool) $this->is_primary,
            'sort_order'  => $this->sort_order,
            'created_at'  => $this->created_at,
            'updated_at'  => $this->updated_at,
        ];
    }
}