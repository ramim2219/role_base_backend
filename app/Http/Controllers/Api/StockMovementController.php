<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StockMovement;
use Illuminate\Http\Request;

class StockMovementController extends Controller
{
    private const OK         = 1200;
    private const NOT_FOUND  = 2001;
    private const SERVER_ERR = 5000;

    private function ok($message = 'Success', $data = null)
    {
        return response()->json(['messageCode' => self::OK, 'message' => $message, 'data' => $data]);
    }

    private function fail($message, $code = self::SERVER_ERR, $data = null)
    {
        return response()->json(['messageCode' => $code, 'message' => $message, 'data' => $data]);
    }

    // GET /api/StockMovement/get_all
    //   ?company_id
    //   ?warehouse_id
    //   ?product_id
    //   ?variant_id
    //   ?movement_type=purchase
    //   ?reference_type=&reference_id=
    //   ?from=YYYY-MM-DD&to=YYYY-MM-DD
    //   ?limit=100  (default 200)
    public function getAll(Request $request)
    {
        try {
            $authUser = $request->user();

            $query = StockMovement::with(['warehouse', 'product', 'variant', 'creator'])
                ->whereHas('product', fn ($q) => $q->where('created_by', $authUser->id))
                ->orderByDesc('created_at');

            if ($request->filled('company_id'))    $query->where('company_id', (int) $request->input('company_id'));
            if ($request->filled('warehouse_id'))  $query->where('warehouse_id', (int) $request->input('warehouse_id'));
            if ($request->filled('product_id'))    $query->where('product_id', (int) $request->input('product_id'));
            if ($request->filled('variant_id'))    $query->where('variant_id', (int) $request->input('variant_id'));
            if ($request->filled('movement_type')) $query->where('movement_type', $request->input('movement_type'));
            if ($request->filled('reference_type'))$query->where('reference_type', $request->input('reference_type'));
            if ($request->filled('reference_id'))  $query->where('reference_id', (int) $request->input('reference_id'));
            if ($request->filled('from'))          $query->whereDate('created_at', '>=', $request->input('from'));
            if ($request->filled('to'))            $query->whereDate('created_at', '<=', $request->input('to'));

            $limit = (int) $request->input('limit', 200);
            $limit = max(1, min($limit, 1000));

            return $this->ok('Movements fetched successfully.', $query->limit($limit)->get()->map(fn ($m) => $m->toApiArray()));
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // GET /api/StockMovement/get_by_id?id=X
    public function getById(Request $request)
    {
        try {
            $id = (int) $request->query('id', 0);
            if (!$id) return $this->fail('id is required.', self::VALIDATION_ERR ?? 4000);

            $authUser = $request->user();
            $row = StockMovement::with(['warehouse', 'product', 'variant', 'creator'])
                ->whereHas('product', fn ($q) => $q->where('created_by', $authUser->id))
                ->find($id);

            if (!$row) return $this->fail('Movement not found.', self::NOT_FOUND);

            return $this->ok('Movement fetched successfully.', $row->toApiArray());
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // GET /api/StockMovement/trace?product_id=X&variant_id=Y&warehouse_id=Z
    //   Returns the full chronological trail for a product/variant.
    //   Useful to answer "how did stock go from 100 to 70?"
    public function trace(Request $request)
    {
        try {
            $authUser  = $request->user();
            $productId = (int) $request->query('product_id', 0);
            if (!$productId) return $this->fail('product_id is required.', 4000);

            $query = StockMovement::with(['warehouse', 'creator'])
                ->where('product_id', $productId)
                ->whereHas('product', fn ($q) => $q->where('created_by', $authUser->id))
                ->orderBy('created_at');

            if ($request->filled('variant_id'))   $query->where('variant_id', (int) $request->input('variant_id'));
            if ($request->filled('warehouse_id')) $query->where('warehouse_id', (int) $request->input('warehouse_id'));

            return $this->ok('Movement trail fetched successfully.', $query->get()->map(fn ($m) => $m->toApiArray()));
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }
}