<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Stock;
use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class StockController extends Controller
{
    private const OK             = 1200;
    private const NOT_FOUND      = 2001;
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

    // GET /api/Stock/get_all
    //   ?company_id=X
    //   ?warehouse_id=X
    //   ?product_id=X
    //   ?variant_id=X
    //   ?low_stock=1  → only rows where available <= reorder_level
    public function getAll(Request $request)
    {
        try {
            $authUser = $request->user();

            $query = Stock::with(['warehouse', 'product', 'variant'])
                ->whereHas('product', fn ($q) => $q->where('created_by', $authUser->id))
                ->orderBy('warehouse_id')
                ->orderBy('product_id');

            if ($request->filled('company_id'))   $query->where('company_id', (int) $request->input('company_id'));
            if ($request->filled('warehouse_id')) $query->where('warehouse_id', (int) $request->input('warehouse_id'));
            if ($request->filled('product_id'))   $query->where('product_id', (int) $request->input('product_id'));
            if ($request->filled('variant_id'))   $query->where('variant_id', (int) $request->input('variant_id'));
            if ($request->boolean('low_stock')) {
                $query->whereColumn('available_quantity', '<=', 'reorder_level');
            }

            return $this->ok('Stocks fetched successfully.', $query->get()->map(fn ($s) => $s->toApiArray()));
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // GET /api/Stock/get_by_id?id=X
    public function getById(Request $request)
    {
        try {
            $id = (int) $request->query('id', 0);
            if (!$id) return $this->fail('id is required.', self::VALIDATION_ERR);

            $authUser = $request->user();
            $stock = Stock::with(['warehouse', 'product', 'variant'])
                ->whereHas('product', fn ($q) => $q->where('created_by', $authUser->id))
                ->find($id);

            if (!$stock) return $this->fail('Stock row not found.', self::NOT_FOUND);

            return $this->ok('Stock fetched successfully.', $stock->toApiArray());
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // GET /api/Stock/get_by_product?product_id=X&variant_id=Y
    //   Returns all warehouse rows for a product (or variant)
    public function getByProduct(Request $request)
    {
        try {
            $authUser = $request->user();
            $productId = (int) $request->query('product_id', 0);
            if (!$productId) return $this->fail('product_id is required.', self::VALIDATION_ERR);

            $query = Stock::with(['warehouse', 'product', 'variant'])
                ->where('product_id', $productId)
                ->whereHas('product', fn ($q) => $q->where('created_by', $authUser->id));

            if ($request->filled('variant_id')) {
                $query->where('variant_id', (int) $request->input('variant_id'));
            }

            return $this->ok('Stock rows fetched successfully.', $query->get()->map(fn ($s) => $s->toApiArray()));
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // POST /api/Stock/save  (upsert a stock row)
    //   Used to initialize a new stock row OR fully set quantities.
    //   Also writes an "opening_stock" or "adjustment" movement.
    public function save(Request $request)
    {
        try {
            $authUser = $request->user();

            $v = Validator::make($request->all(), [
                'company_id'         => 'required|integer|exists:companies,id',
                'warehouse_id'       => 'required|integer|exists:warehouses,id',
                'product_id'         => 'required|integer|exists:products,id',
                'variant_id'         => 'nullable|integer|exists:product_variants,id',
                'quantity'           => 'required|numeric',
                'reserved_quantity'  => 'nullable|numeric|min:0',
                'reorder_level'      => 'nullable|numeric|min:0',
                'reorder_quantity'   => 'nullable|numeric|min:0',
                'movement_type'      => 'nullable|in:opening_stock,adjustment',
            ]);

            if ($v->fails()) return $this->fail($v->errors()->first(), self::VALIDATION_ERR);

            $warehouseId = (int) $request->input('warehouse_id');
            $productId   = (int) $request->input('product_id');
            $variantId   = $request->filled('variant_id') ? (int) $request->input('variant_id') : null;
            $quantity    = (float) $request->input('quantity');
            $reserved    = (float) $request->input('reserved_quantity', 0);
            $movement    = $request->input('movement_type', 'opening_stock');

            $result = DB::transaction(function () use (
                $authUser, $warehouseId, $productId, $variantId,
                $quantity, $reserved, $movement, $request
            ) {
                $stock = Stock::firstOrNew([
                    'warehouse_id' => $warehouseId,
                    'product_id'   => $productId,
                    'variant_id'   => $variantId,
                ]);

                $previousQty = (float) ($stock->quantity ?? 0);
                $isNew = !$stock->exists;

                $stock->company_id          = (int) $request->input('company_id');
                $stock->quantity            = $quantity;
                $stock->reserved_quantity   = $reserved;
                $stock->available_quantity  = max(0, $quantity - $reserved);
                $stock->reorder_level       = (float) $request->input('reorder_level', $stock->reorder_level ?? 0);
                $stock->reorder_quantity    = (float) $request->input('reorder_quantity', $stock->reorder_quantity ?? 0);
                $stock->save();

                StockMovement::create([
                    'company_id'        => $stock->company_id,
                    'warehouse_id'      => $warehouseId,
                    'product_id'        => $productId,
                    'variant_id'        => $variantId,
                    'movement_type'     => $isNew ? 'opening_stock' : $movement,
                    'reference_type'    => null,
                    'reference_id'      => null,
                    'quantity'          => $quantity - $previousQty,
                    'previous_quantity' => $previousQty,
                    'new_quantity'      => $quantity,
                    'note'              => $isNew ? 'Stock row created' : 'Stock set via save',
                    'created_by'        => $authUser->id,
                ]);

                return $stock;
            });

            return $this->ok('Stock saved successfully.', $result->toApiArray());
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // POST /api/Stock/adjust
    //   Adjust quantity by a signed delta (+/-) with a movement type.
    //   Used for purchase, sale, damage, expired, adjustment, transfer_*, etc.
    public function adjust(Request $request)
    {
        try {
            $authUser = $request->user();

            $v = Validator::make($request->all(), [
                'company_id'      => 'required|integer|exists:companies,id',
                'warehouse_id'    => 'required|integer|exists:warehouses,id',
                'product_id'      => 'required|integer|exists:products,id',
                'variant_id'      => 'nullable|integer|exists:product_variants,id',
                'quantity'        => 'required|numeric|not_in:0',   // signed delta
                'movement_type'   => 'required|string|in:' . implode(',', StockMovement::TYPES),
                'reference_type'  => 'nullable|string|max:50',
                'reference_id'    => 'nullable|integer',
                'note'            => 'nullable|string',
            ]);

            if ($v->fails()) return $this->fail($v->errors()->first(), self::VALIDATION_ERR);

            $warehouseId = (int) $request->input('warehouse_id');
            $productId   = (int) $request->input('product_id');
            $variantId   = $request->filled('variant_id') ? (int) $request->input('variant_id') : null;
            $delta       = (float) $request->input('quantity');

            $result = DB::transaction(function () use (
                $authUser, $warehouseId, $productId, $variantId, $delta, $request
            ) {
                $stock = Stock::firstOrNew([
                    'warehouse_id' => $warehouseId,
                    'product_id'   => $productId,
                    'variant_id'   => $variantId,
                ]);

                if (!$stock->exists) {
                    $stock->company_id = (int) $request->input('company_id');
                    $stock->quantity = 0;
                    $stock->reserved_quantity = 0;
                    $stock->available_quantity = 0;
                    $stock->reorder_level = 0;
                    $stock->reorder_quantity = 0;
                }

                $previousQty = (float) $stock->quantity;
                $newQty = $previousQty + $delta;

                if ($newQty < 0) {
                    throw new \RuntimeException(
                        "Adjustment would make stock negative (current: {$previousQty}, delta: {$delta})."
                    );
                }

                $stock->quantity = $newQty;
                $stock->available_quantity = max(0, $newQty - (float) $stock->reserved_quantity);
                $stock->save();

                StockMovement::create([
                    'company_id'        => $stock->company_id,
                    'warehouse_id'      => $warehouseId,
                    'product_id'        => $productId,
                    'variant_id'        => $variantId,
                    'movement_type'     => $request->input('movement_type'),
                    'reference_type'    => $request->input('reference_type'),
                    'reference_id'      => $request->input('reference_id'),
                    'quantity'          => $delta,
                    'previous_quantity' => $previousQty,
                    'new_quantity'      => $newQty,
                    'note'              => $request->input('note'),
                    'created_by'        => $authUser->id,
                ]);

                return $stock;
            });

            return $this->ok('Stock adjusted successfully.', $result->toApiArray());
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // PUT /api/Stock/update_reorder
    //   Update only the reorder thresholds without moving stock.
    public function updateReorder(Request $request)
    {
        try {
            $authUser = $request->user();
            $id = (int) $request->input('id');
            if (!$id) return $this->fail('id is required.', self::VALIDATION_ERR);

            $stock = Stock::with('product')->find($id);
            if (!$stock || (int) $stock->product?->created_by !== (int) $authUser->id) {
                return $this->fail('Stock row not found.', self::NOT_FOUND);
            }

            $v = Validator::make($request->all(), [
                'reorder_level'    => 'sometimes|numeric|min:0',
                'reorder_quantity' => 'sometimes|numeric|min:0',
            ]);
            if ($v->fails()) return $this->fail($v->errors()->first(), self::VALIDATION_ERR);

            $data = [];
            if ($request->has('reorder_level'))    $data['reorder_level']    = (float) $request->input('reorder_level');
            if ($request->has('reorder_quantity')) $data['reorder_quantity'] = (float) $request->input('reorder_quantity');

            if (empty($data)) return $this->fail('No fields to update.', self::VALIDATION_ERR);

            $stock->update($data);

            return $this->ok('Reorder thresholds updated.', $stock->fresh()->toApiArray());
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }
}