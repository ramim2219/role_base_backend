<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Barcode extends Model
{
    use HasFactory;

    public const TYPES = ['internal', 'ean', 'upc', 'supplier', 'other'];

    protected $fillable = [
        'company_id',
        'product_id',
        'variant_id',
        'barcode',
        'barcode_type',
        'is_primary',
        'status',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'product_id' => 'integer',
        'variant_id' => 'integer',
        'is_primary' => 'boolean',
        'status'     => 'integer',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
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
            'id'           => $this->id,
            'company_id'   => $this->company_id,
            'product_id'   => $this->product_id,
            'variant_id'   => $this->variant_id,
            'barcode'      => $this->barcode,
            'barcode_type' => $this->barcode_type,
            'is_primary'   => (bool) $this->is_primary,
            'status'       => $this->status,
            'created_at'   => $this->created_at,
            'updated_at'   => $this->updated_at,
        ];
    }
}