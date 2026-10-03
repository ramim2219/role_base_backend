<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductSupplier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ProductSupplierController extends Controller
{
    private const OK             = 1200;
    private const NOT_FOUND      = 2001;
    private const DUPLICATE      = 3002;
    private const VALIDATION_ERR = 4000;
    private const SERVER_ERR     = 5000;

    private function ok($message = 'Success', $data = null)
    {
        return response()->json(['messageCode' => self::OK, 'message' => $message, 'data' => $data]);
    }

    private function fail($message, $code = self::SERVER_ERR, $data = null)
    {
        return response()->json(['messageCode' => $code, 'message' => $message, 'data' => $data]);
    }

    // GET /api/ProductSupplier/get_all
    public function getAll(Request $request)
    {
        try {
            $authUser = $request->user();

            $query = ProductSupplier::with(['product', 'variant', 'supplier'])
                ->whereHas('product', fn ($q) => $q->where('created_by', $authUser->id))
                ->orderByDesc('is_preferred')
                ->orderBy('purchase_price');

            if ($request->filled('product_id'))  $query->where('product_id', (int) $request->input('product_id'));
            if ($request->filled('variant_id'))  $query->where('variant_id', (int) $request->input('variant_id'));
            if ($request->filled('supplier_id')) $query->where('supplier_id', (int) $request->input('supplier_id'));
            if ($request->filled('status'))      $query->where('status', (int) $request->input('status'));

            return $this->ok('Links fetched successfully.', $query->get()->map(fn ($r) => $r->toApiArray()));
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // POST /api/ProductSupplier/save
    public function save(Request $request)
    {
        try {
            $authUser = $request->user();

            $v = Validator::make($request->all(), [
                'product_id'        => 'required|integer|exists:products,id',
                'variant_id'        => 'nullable|integer|exists:product_variants,id',
                'supplier_id'       => 'required|integer|exists:suppliers,id',
                'supplier_sku'      => 'nullable|string|max:100',
                'purchase_price'    => 'nullable|numeric|min:0',
                'minimum_order_qty' => 'nullable|integer|min:1',
                'is_preferred'      => 'nullable|boolean',
                'status'            => 'nullable|integer|in:0,1',
            ]);

            if ($v->fails()) return $this->fail($v->errors()->first(), self::VALIDATION_ERR);

            $productId  = (int) $request->input('product_id');
            $variantId  = $request->filled('variant_id') ? (int) $request->input('variant_id') : null;
            $supplierId = (int) $request->input('supplier_id');

            $dup = ProductSupplier::where('product_id', $productId)
                ->where('variant_id', $variantId)
                ->where('supplier_id', $supplierId)
                ->exists();

            if ($dup) {
                return $this->fail('This supplier is already linked to this product/variant.', self::DUPLICATE);
            }

            $isPreferred = (bool) $request->input('is_preferred', false);
            if ($isPreferred) {
                ProductSupplier::where('product_id', $productId)
                    ->where('variant_id', $variantId)
                    ->update(['is_preferred' => false]);
            }

            $row = ProductSupplier::create([
                'product_id'        => $productId,
                'variant_id'        => $variantId,
                'supplier_id'       => $supplierId,
                'supplier_sku'      => $request->input('supplier_sku'),
                'purchase_price'    => (float) $request->input('purchase_price', 0),
                'minimum_order_qty' => (int) $request->input('minimum_order_qty', 1),
                'is_preferred'      => $isPreferred,
                'status'            => (int) $request->input('status', 1),
            ]);

            return $this->ok('Link created successfully.', ['id' => $row->id]);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // PUT /api/ProductSupplier/update
    public function update(Request $request)
    {
        try {
            $authUser = $request->user();
            $id = (int) $request->input('id');
            if (!$id) return $this->fail('id is required.', self::VALIDATION_ERR);

            $row = ProductSupplier::with('product')->find($id);
            if (!$row || (int) $row->product?->created_by !== (int) $authUser->id) {
                return $this->fail('Link not found.', self::NOT_FOUND);
            }

            $v = Validator::make($request->all(), [
                'supplier_sku'      => 'sometimes|nullable|string|max:100',
                'purchase_price'    => 'sometimes|numeric|min:0',
                'minimum_order_qty' => 'sometimes|integer|min:1',
                'is_preferred'      => 'sometimes|boolean',
                'status'            => 'sometimes|integer|in:0,1',
            ]);

            if ($v->fails()) return $this->fail($v->errors()->first(), self::VALIDATION_ERR);

            $data = [];
            if ($request->has('supplier_sku'))      $data['supplier_sku']      = $request->input('supplier_sku');
            if ($request->has('purchase_price'))    $data['purchase_price']    = (float) $request->input('purchase_price');
            if ($request->has('minimum_order_qty')) $data['minimum_order_qty'] = (int) $request->input('minimum_order_qty');
            if ($request->has('status'))            $data['status']            = (int) $request->input('status');

            if ($request->has('is_preferred') && $request->boolean('is_preferred')) {
                ProductSupplier::where('product_id', $row->product_id)
                    ->where('variant_id', $row->variant_id)
                    ->update(['is_preferred' => false]);
                $data['is_preferred'] = true;
            } elseif ($request->has('is_preferred')) {
                $data['is_preferred'] = false;
            }

            if (empty($data)) return $this->fail('No fields to update.', self::VALIDATION_ERR);

            $row->update($data);

            return $this->ok('Link updated successfully.', $row->fresh()->load(['product','variant','supplier'])->toApiArray());
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // DELETE /api/ProductSupplier/delete
    public function delete(Request $request)
    {
        try {
            $authUser = $request->user();
            $id = (int) ($request->input('id') ?? $request->query('id') ?? 0);
            if (!$id) return $this->fail('id is required.', self::VALIDATION_ERR);

            $row = ProductSupplier::with('product')->find($id);
            if (!$row || (int) $row->product?->created_by !== (int) $authUser->id) {
                return $this->fail('Link not found.', self::NOT_FOUND);
            }

            $row->delete();
            return $this->ok('Link deleted successfully.', ['id' => $id]);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }
}