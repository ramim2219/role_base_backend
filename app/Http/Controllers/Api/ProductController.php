<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Barcode;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\ProductVariantValue;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class ProductController extends Controller
{
    private const OK             = 1200;
    private const NOT_FOUND      = 2001;
    private const FORBIDDEN      = 3001;
    private const DUPLICATE      = 3002;
    private const VALIDATION_ERR = 4000;
    private const SERVER_ERR     = 5000;

    private function ok($message = 'Success', $data = null)
    {
        return response()->json([
            'messageCode' => self::OK,
            'message'     => $message,
            'data'        => $data,
        ]);
    }

    private function fail($message, $code = self::SERVER_ERR, $data = null)
    {
        return response()->json([
            'messageCode' => $code,
            'message'     => $message,
            'data'        => $data,
        ]);
    }

    private function findOwned(int $id, $authUser): ?Product
    {
        return Product::where('id', $id)
            ->where('created_by', $authUser->id)
            ->first();
    }

    // ─── File storage helper ─────────────────────────────
    private function storeFile($file, string $folder): string
    {
        return $file->store($folder, 'public');
    }

    private function deleteFileIfAny(?string $path): void
    {
        if ($path && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }

    // ═════════════════════════════════════════════════════
    // 1. LIST
    // GET /api/Product/get_all
    //   ?company_id=X
    //   ?category_id=X
    //   ?brand_id=X
    //   ?product_type_id=X
    //   ?status=1|0
    //   ?search=name
    // ═════════════════════════════════════════════════════
    public function getAll(Request $request)
    {
        try {
            $authUser = $request->user();

            $query = Product::with(['category', 'brand', 'type', 'company'])
                ->where('created_by', $authUser->id)
                ->orderBy('name');

            if ($request->filled('company_id')) {
                $query->where('company_id', (int) $request->input('company_id'));
            }
            if ($request->filled('category_id')) {
                $query->where('category_id', (int) $request->input('category_id'));
            }
            if ($request->filled('brand_id')) {
                $query->where('brand_id', (int) $request->input('brand_id'));
            }
            if ($request->filled('product_type_id')) {
                $query->where('product_type_id', (int) $request->input('product_type_id'));
            }
            if ($request->filled('status')) {
                $query->where('status', (int) $request->input('status'));
            }
            if ($request->filled('search')) {
                $term = '%' . $request->input('search') . '%';
                $query->where(function ($q) use ($term) {
                    $q->where('name', 'like', $term)
                      ->orWhere('base_sku', 'like', $term)
                      ->orWhere('base_barcode', 'like', $term);
                });
            }

            $rows = $query->get()->map(fn ($p) => $p->toApiArray());

            return $this->ok('Products fetched successfully.', $rows);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // ═════════════════════════════════════════════════════
    // 2. GET BY ID (detailed)
    // GET /api/Product/get_by_id?id=X
    //   with=attributes,variants,images,barcodes
    //   ?detailed=1 → include everything
    // ═════════════════════════════════════════════════════
    public function getById(Request $request)
    {
        try {
            $id = (int) $request->query('id', 0);
            if (!$id) {
                return $this->fail('id is required.', self::VALIDATION_ERR);
            }

            $authUser = $request->user();
            $product = $this->findOwned($id, $authUser);
            if (!$product) {
                return $this->fail(
                    'Product not found or not under your administration.',
                    self::NOT_FOUND
                );
            }

            $detailed = $request->boolean('detailed', true);
            $with = ['category', 'brand', 'type', 'company'];

            if ($detailed) {
                $with = array_merge($with, [
                    'attributes.attribute.values',
                    'variants.values.attribute',
                    'variants.values.attributeValue',
                    'images',
                    'barcodes',
                ]);
            }

            $product->load($with);

            return $this->ok('Product fetched successfully.', $product->toApiArray($detailed));
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // ═════════════════════════════════════════════════════
    // 3. CREATE (with nested children)
    // POST /api/Product/save   (multipart/form-data)
    //
    // Base body:
    //   company_id, category_id?, brand_id?, product_type_id?
    //   name, description?, base_sku?, base_barcode?
    //   has_variants?, track_stock?, track_serial?,
    //   allow_purchase?, allow_sale?, status?
    //
    //   product_image (file, optional)
    //
    // Nested (JSON-encoded when multipart):
    //   attributes[]              → [{ attribute_id, is_required? }, ...]
    //   variants[]                → [{ sku?, barcode?, variant_name?,
    //                                 purchase_price?, selling_price?,
    //                                 mrp?, wholesale_price?, weight?,
    //                                 track_stock?, track_serial?,
    //                                 status?,
    //                                 values[] → [{ attribute_id, attribute_value_id }],
    //                               }, ...]
    //   barcodes[]                → [{ barcode, barcode_type?, variant_index?, is_primary? }, ...]
    //   images[]                  → [{ image (File), variant_index?, is_primary?, sort_order? }, ...]
    // ═════════════════════════════════════════════════════
    public function save(Request $request)
    {
        try {
            $authUser = $request->user();

            // ─── Base validation ─────────────────────────
            $v = Validator::make($request->all(), [
                'company_id'       => 'required|integer|exists:companies,id',
                'category_id'      => 'nullable|integer|exists:categories,id',
                'brand_id'         => 'nullable|integer|exists:brands,id',
                'product_type_id'  => 'nullable|integer|exists:product_types,id',

                'name'             => 'required|string|max:200',
                'description'      => 'nullable|string',
                'base_sku'         => 'nullable|string|max:100',
                'base_barcode'     => 'nullable|string|max:100',

                'has_variants'     => 'nullable|boolean',
                'track_stock'      => 'nullable|boolean',
                'track_serial'     => 'nullable|boolean',
                'allow_purchase'   => 'nullable|boolean',
                'allow_sale'       => 'nullable|boolean',
                'status'           => 'nullable|integer|in:0,1',

                'product_image'    => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            ]);

            if ($v->fails()) {
                return $this->fail($v->errors()->first(), self::VALIDATION_ERR);
            }

            // ─── Decode nested arrays ─────────────────────
            $attributes = $this->decodeArray($request->input('attributes', []));
            $variants   = $this->decodeArray($request->input('variants', []));
            $barcodes   = $this->decodeArray($request->input('barcodes', []));
            // images come as real files → read from the request

            $companyId = (int) $request->input('company_id');
            $hasVariants = (bool) $request->input('has_variants', false);

            // If variants provided, force has_variants = true
            if (!empty($variants)) {
                $hasVariants = true;
            }

            // ─── Duplicate sku check (per company) ───────
            $baseSku = $request->input('base_sku');
            if ($baseSku) {
                $dup = Product::where('company_id', $companyId)
                    ->where('base_sku', $baseSku)
                    ->exists();
                if ($dup) {
                    return $this->fail('Base SKU is already in use.', self::DUPLICATE);
                }
            }

            $result = DB::transaction(function () use (
                $request, $authUser, $companyId, $hasVariants,
                $attributes, $variants, $barcodes
            ) {
                // ─── Create the product ──────────────────
                $productImage = null;
                if ($request->hasFile('product_image')) {
                    $productImage = $this->storeFile(
                        $request->file('product_image'),
                        'products'
                    );
                }

                $product = Product::create([
                    'company_id'      => $companyId,
                    'category_id'     => $request->input('category_id'),
                    'brand_id'        => $request->input('brand_id'),
                    'product_type_id' => $request->input('product_type_id'),

                    'name'            => trim($request->input('name')),
                    'description'     => $request->input('description'),
                    'base_sku'        => $request->input('base_sku'),
                    'base_barcode'    => $request->input('base_barcode'),
                    'product_image'   => $productImage,

                    'has_variants'    => $hasVariants,
                    'track_stock'     => (bool) $request->input('track_stock', true),
                    'track_serial'    => (bool) $request->input('track_serial', false),
                    'allow_purchase'  => (bool) $request->input('allow_purchase', true),
                    'allow_sale'      => (bool) $request->input('allow_sale', true),
                    'status'          => (int) $request->input('status', 1),
                    'created_by'      => $authUser->id,
                ]);

                // ─── Product attributes ─────────────────
                foreach ($attributes as $attr) {
                    if (empty($attr['attribute_id'])) continue;
                    ProductAttribute::create([
                        'product_id'   => $product->id,
                        'attribute_id' => (int) $attr['attribute_id'],
                        'is_required'  => isset($attr['is_required'])
                            ? (bool) $attr['is_required']
                            : true,
                    ]);
                }

                // ─── Variants + their values ────────────
                $variantIdByIndex = []; // for barcodes/images referencing a variant by index
                foreach ($variants as $i => $var) {
                    $variant = ProductVariant::create([
                        'product_id'      => $product->id,
                        'sku'             => $var['sku'] ?? null,
                        'barcode'         => $var['barcode'] ?? null,
                        'variant_name'    => $var['variant_name'] ?? null,
                        'purchase_price'  => $var['purchase_price'] ?? 0,
                        'selling_price'   => $var['selling_price'] ?? 0,
                        'mrp'             => $var['mrp'] ?? 0,
                        'wholesale_price' => $var['wholesale_price'] ?? 0,
                        'weight'          => $var['weight'] ?? null,
                        'track_stock'     => isset($var['track_stock']) ? (bool) $var['track_stock'] : true,
                        'track_serial'    => isset($var['track_serial']) ? (bool) $var['track_serial'] : false,
                        'status'          => (int) ($var['status'] ?? 1),
                    ]);

                    $variantIdByIndex[$i] = $variant->id;

                    // values: [{ attribute_id, attribute_value_id }, ...]
                    $values = $var['values'] ?? [];
                    foreach ($values as $val) {
                        if (empty($val['attribute_id']) || empty($val['attribute_value_id'])) {
                            continue;
                        }
                        ProductVariantValue::create([
                            'variant_id'         => $variant->id,
                            'attribute_id'       => (int) $val['attribute_id'],
                            'attribute_value_id' => (int) $val['attribute_value_id'],
                        ]);
                    }
                }

                // ─── Barcodes ───────────────────────────
                foreach ($barcodes as $bc) {
                    if (empty($bc['barcode'])) continue;

                    $variantId = null;
                    if (isset($bc['variant_index']) && isset($variantIdByIndex[(int) $bc['variant_index']])) {
                        $variantId = $variantIdByIndex[(int) $bc['variant_index']];
                    }

                    // Duplicate check per company
                    $dupBc = Barcode::where('company_id', $companyId)
                        ->where('barcode', $bc['barcode'])
                        ->exists();
                    if ($dupBc) {
                        throw new \RuntimeException(
                            "Barcode '{$bc['barcode']}' is already in use."
                        );
                    }

                    Barcode::create([
                        'company_id'   => $companyId,
                        'product_id'   => $product->id,
                        'variant_id'   => $variantId,
                        'barcode'      => $bc['barcode'],
                        'barcode_type' => $bc['barcode_type'] ?? 'internal',
                        'is_primary'   => !empty($bc['is_primary']),
                        'status'       => 1,
                    ]);
                }

                // ─── Product images (files) ─────────────
                if ($request->hasFile('images')) {
                    $files = $request->file('images');
                    $meta  = $request->input('images_meta', []);
                    $meta  = $this->decodeArray($meta);

                    foreach ($files as $idx => $file) {
                        $m = $meta[$idx] ?? [];
                        $path = $this->storeFile($file, 'products');

                        $variantId = null;
                        if (isset($m['variant_index']) && isset($variantIdByIndex[(int) $m['variant_index']])) {
                            $variantId = $variantIdByIndex[(int) $m['variant_index']];
                        }

                        ProductImage::create([
                            'product_id'  => $product->id,
                            'variant_id'  => $variantId,
                            'image'       => $path,
                            'is_primary'  => !empty($m['is_primary']),
                            'sort_order'  => (int) ($m['sort_order'] ?? $idx),
                        ]);
                    }
                }

                return $product;
            });

            return $this->ok('Product created successfully.', [
                'id' => $result->id,
            ]);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // ═════════════════════════════════════════════════════
    // 4. UPDATE (base fields only — nested editing via separate endpoints)
    // PUT /api/Product/update
    // ═════════════════════════════════════════════════════
    public function update(Request $request)
    {
        try {
            $authUser = $request->user();
            $id       = (int) $request->input('id');

            if (!$id) {
                return $this->fail('id is required.', self::VALIDATION_ERR);
            }

            $product = $this->findOwned($id, $authUser);
            if (!$product) {
                return $this->fail(
                    'Product not found or not under your administration.',
                    self::NOT_FOUND
                );
            }

            $v = Validator::make($request->all(), [
                'category_id'     => 'sometimes|nullable|integer|exists:categories,id',
                'brand_id'        => 'sometimes|nullable|integer|exists:brands,id',
                'product_type_id' => 'sometimes|nullable|integer|exists:product_types,id',
                'name'            => 'sometimes|string|max:200',
                'description'     => 'sometimes|nullable|string',
                'base_sku'        => 'sometimes|nullable|string|max:100',
                'base_barcode'    => 'sometimes|nullable|string|max:100',
                'has_variants'    => 'sometimes|boolean',
                'track_stock'     => 'sometimes|boolean',
                'track_serial'    => 'sometimes|boolean',
                'allow_purchase'  => 'sometimes|boolean',
                'allow_sale'      => 'sometimes|boolean',
                'status'          => 'sometimes|integer|in:0,1',
                'product_image'   => 'sometimes|nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            ]);

            if ($v->fails()) {
                return $this->fail($v->errors()->first(), self::VALIDATION_ERR);
            }

            $data = [];

            foreach ([
                'category_id', 'brand_id', 'product_type_id',
                'name', 'description', 'base_sku', 'base_barcode',
            ] as $field) {
                if ($request->has($field)) {
                    $data[$field] = $request->input($field);
                }
            }

            foreach ([
                'has_variants', 'track_stock', 'track_serial',
                'allow_purchase', 'allow_sale',
            ] as $flag) {
                if ($request->has($flag)) {
                    $data[$flag] = (bool) $request->input($flag);
                }
            }

            if ($request->has('status')) {
                $data['status'] = (int) $request->input('status');
            }

            // Replace product image
            if ($request->hasFile('product_image')) {
                $this->deleteFileIfAny($product->product_image);
                $data['product_image'] = $this->storeFile(
                    $request->file('product_image'),
                    'products'
                );
            }

            // Duplicate base_sku check
            if (array_key_exists('base_sku', $data) && $data['base_sku']) {
                $dup = Product::where('company_id', $product->company_id)
                    ->where('base_sku', $data['base_sku'])
                    ->where('id', '!=', $id)
                    ->exists();
                if ($dup) {
                    return $this->fail('Base SKU is already in use.', self::DUPLICATE);
                }
            }

            if (empty($data)) {
                return $this->fail('No fields to update.', self::VALIDATION_ERR);
            }

            $product->update($data);

            return $this->ok(
                'Product updated successfully.',
                $product->fresh()->load(['category', 'brand', 'type'])->toApiArray()
            );
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // ═════════════════════════════════════════════════════
    // 5. DELETE (cascades to children via FK)
    // ═════════════════════════════════════════════════════
    public function delete(Request $request)
    {
        try {
            $authUser = $request->user();

            $id = (int) ($request->input('id') ?? $request->query('id') ?? 0);
            if (!$id) {
                return $this->fail('id is required.', self::VALIDATION_ERR);
            }

            $product = $this->findOwned($id, $authUser);
            if (!$product) {
                return $this->fail(
                    'Product not found or not under your administration.',
                    self::NOT_FOUND
                );
            }

            // Clean up files
            $this->deleteFileIfAny($product->product_image);
            $product->load(['images', 'variants']);
            foreach ($product->images as $img) {
                $this->deleteFileIfAny($img->image);
            }
            foreach ($product->variants as $v) {
                $this->deleteFileIfAny($v->image);
            }

            $product->delete();

            return $this->ok('Product deleted successfully.', ['id' => $id]);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // ─── Helpers ─────────────────────────────────────────
    /**
     * If the input is a JSON string (as happens with multipart),
     * decode it to an array. Otherwise return as-is.
     */
    private function decodeArray($value): array
    {
        if (is_array($value)) return $value;
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }
}