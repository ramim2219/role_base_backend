<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'category_id',
        'brand_id',
        'product_type_id',
        'name',
        'description',
        'base_sku',
        'base_barcode',
        'product_image',
        'has_variants',
        'track_stock',
        'track_serial',
        'allow_purchase',
        'allow_sale',
        'status',
        'created_by',
    ];

    protected $casts = [
        'company_id'      => 'integer',
        'category_id'     => 'integer',
        'brand_id'        => 'integer',
        'product_type_id' => 'integer',
        'has_variants'    => 'boolean',
        'track_stock'     => 'boolean',
        'track_serial'    => 'boolean',
        'allow_purchase'  => 'boolean',
        'allow_sale'      => 'boolean',
        'status'          => 'integer',
        'created_by'      => 'integer',
    ];

    // ─── Relationships ──────────────────────────────────
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(ProductType::class, 'product_type_id');
    }

    public function attributes(): HasMany
    {
        return $this->hasMany(ProductAttribute::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order');
    }

    public function barcodes(): HasMany
    {
        return $this->hasMany(Barcode::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ─── Scopes ─────────────────────────────────────────
    public function scopeActive($query)
    {
        return $query->where('status', 1);
    }

    // ─── API shape ──────────────────────────────────────
    public function toApiArray(bool $detailed = false): array
    {
        $data = [
            'id'              => $this->id,
            'company_id'      => $this->company_id,
            'company'         => $this->relationLoaded('company') && $this->company
                ? ['id' => $this->company->id, 'name' => $this->company->name]
                : null,
            'category_id'     => $this->category_id,
            'category'        => $this->relationLoaded('category') && $this->category
                ? ['id' => $this->category->id, 'name' => $this->category->name]
                : null,
            'brand_id'        => $this->brand_id,
            'brand'           => $this->relationLoaded('brand') && $this->brand
                ? ['id' => $this->brand->id, 'name' => $this->brand->name]
                : null,
            'product_type_id' => $this->product_type_id,
            'product_type'    => $this->relationLoaded('type') && $this->type
                ? ['id' => $this->type->id, 'name' => $this->type->name]
                : null,
            'name'            => $this->name,
            'description'     => $this->description,
            'base_sku'        => $this->base_sku,
            'base_barcode'    => $this->base_barcode,
            'product_image'   => $this->product_image,
            'product_image_url'=> $this->product_image
                ? asset('storage/' . $this->product_image)
                : null,
            'has_variants'    => (bool) $this->has_variants,
            'track_stock'     => (bool) $this->track_stock,
            'track_serial'    => (bool) $this->track_serial,
            'allow_purchase'  => (bool) $this->allow_purchase,
            'allow_sale'      => (bool) $this->allow_sale,
            'status'          => $this->status,
            'created_by'      => $this->created_by,
            'created_at'      => $this->created_at,
            'updated_at'      => $this->updated_at,
        ];

        if ($detailed) {
            $data['attributes'] = $this->relationLoaded('attributes')
                ? $this->attributes->map(fn ($a) => $a->toApiArray())->values()
                : [];
            $data['variants'] = $this->relationLoaded('variants')
                ? $this->variants->map(fn ($v) => $v->toApiArray(true))->values()
                : [];
            $data['images'] = $this->relationLoaded('images')
                ? $this->images->map(fn ($i) => $i->toApiArray())->values()
                : [];
            $data['barcodes'] = $this->relationLoaded('barcodes')
                ? $this->barcodes->map(fn ($b) => $b->toApiArray())->values()
                : [];
        }

        return $data;
    }
}