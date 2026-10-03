<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Barcode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class BarcodeController extends Controller
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

    private function findOwned(int $id, $authUser): ?Barcode
    {
        return Barcode::with(['product', 'variant'])
            ->where('id', $id)
            ->whereHas('product', function ($q) use ($authUser) {
                $q->where('created_by', $authUser->id);
            })
            ->first();
    }

    // ═════════════════════════════════════════════════════
    // 1. LIST
    // GET /api/Barcode/get_all
    //   ?company_id=X
    //   ?product_id=X
    //   ?variant_id=X
    //   ?barcode_type=ean
    //   ?status=1|0
    //   ?search=12345
    // ═════════════════════════════════════════════════════
    public function getAll(Request $request)
    {
        try {
            $authUser = $request->user();

            $query = Barcode::with(['product', 'variant'])
                ->whereHas('product', function ($q) use ($authUser) {
                    $q->where('created_by', $authUser->id);
                })
                ->orderByDesc('id');

            if ($request->filled('company_id')) {
                $query->where('company_id', (int) $request->input('company_id'));
            }
            if ($request->filled('product_id')) {
                $query->where('product_id', (int) $request->input('product_id'));
            }
            if ($request->filled('variant_id')) {
                $query->where('variant_id', (int) $request->input('variant_id'));
            }
            if ($request->filled('barcode_type')) {
                $query->where('barcode_type', $request->input('barcode_type'));
            }
            if ($request->filled('status')) {
                $query->where('status', (int) $request->input('status'));
            }
            if ($request->filled('search')) {
                $term = '%' . $request->input('search') . '%';
                $query->where('barcode', 'like', $term);
            }

            $rows = $query->get()->map(fn ($b) => $b->toApiArray());

            return $this->ok('Barcodes fetched successfully.', $rows);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // ═════════════════════════════════════════════════════
    // 2. GET BY ID
    // ═════════════════════════════════════════════════════
    public function getById(Request $request)
    {
        try {
            $id = (int) $request->query('id', 0);
            if (!$id) {
                return $this->fail('id is required.', self::VALIDATION_ERR);
            }

            $authUser = $request->user();
            $row = $this->findOwned($id, $authUser);
            if (!$row) {
                return $this->fail(
                    'Barcode not found or not under your administration.',
                    self::NOT_FOUND
                );
            }

            return $this->ok('Barcode fetched successfully.', $row->toApiArray());
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // ═════════════════════════════════════════════════════
    // 3. CREATE
    // POST /api/Barcode/save
    // ═════════════════════════════════════════════════════
    public function save(Request $request)
    {
        try {
            $authUser = $request->user();

            $allowed = implode(',', Barcode::TYPES);

            $v = Validator::make($request->all(), [
                'company_id'   => 'required|integer|exists:companies,id',
                'product_id'   => 'required|integer|exists:products,id',
                'variant_id'   => 'nullable|integer|exists:product_variants,id',
                'barcode'      => 'required|string|max:150',
                'barcode_type' => "nullable|string|in:{$allowed}",
                'is_primary'   => 'nullable|boolean',
                'status'       => 'nullable|integer|in:0,1',
            ]);

            if ($v->fails()) {
                return $this->fail($v->errors()->first(), self::VALIDATION_ERR);
            }

            $companyId = (int) $request->input('company_id');
            $barcode   = trim($request->input('barcode'));

            $dup = Barcode::where('company_id', $companyId)
                ->where('barcode', $barcode)
                ->exists();
            if ($dup) {
                return $this->fail('This barcode is already in use.', self::DUPLICATE);
            }

            $row = Barcode::create([
                'company_id'   => $companyId,
                'product_id'   => (int) $request->input('product_id'),
                'variant_id'   => $request->input('variant_id'),
                'barcode'      => $barcode,
                'barcode_type' => $request->input('barcode_type', 'internal'),
                'is_primary'   => (bool) $request->input('is_primary', false),
                'status'       => (int) $request->input('status', 1),
            ]);

            return $this->ok('Barcode created successfully.', ['id' => $row->id]);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // ═════════════════════════════════════════════════════
    // 4. UPDATE
    // PUT /api/Barcode/update
    // ═════════════════════════════════════════════════════
    public function update(Request $request)
    {
        try {
            $authUser = $request->user();
            $id       = (int) $request->input('id');

            if (!$id) {
                return $this->fail('id is required.', self::VALIDATION_ERR);
            }

            $row = $this->findOwned($id, $authUser);
            if (!$row) {
                return $this->fail(
                    'Barcode not found or not under your administration.',
                    self::NOT_FOUND
                );
            }

            $allowed = implode(',', Barcode::TYPES);

            $v = Validator::make($request->all(), [
                'barcode'      => 'sometimes|string|max:150',
                'barcode_type' => "sometimes|string|in:{$allowed}",
                'is_primary'   => 'sometimes|boolean',
                'status'       => 'sometimes|integer|in:0,1',
            ]);

            if ($v->fails()) {
                return $this->fail($v->errors()->first(), self::VALIDATION_ERR);
            }

            $data = [];
            if ($request->filled('barcode'))      $data['barcode']      = trim($request->input('barcode'));
            if ($request->filled('barcode_type')) $data['barcode_type'] = $request->input('barcode_type');
            if ($request->has('is_primary'))      $data['is_primary']   = (bool) $request->input('is_primary');
            if ($request->has('status'))          $data['status']       = (int) $request->input('status');

            if (array_key_exists('barcode', $data)) {
                $dup = Barcode::where('company_id', $row->company_id)
                    ->where('barcode', $data['barcode'])
                    ->where('id', '!=', $id)
                    ->exists();
                if ($dup) {
                    return $this->fail('This barcode is already in use.', self::DUPLICATE);
                }
            }

            if (empty($data)) {
                return $this->fail('No fields to update.', self::VALIDATION_ERR);
            }

            $row->update($data);

            return $this->ok(
                'Barcode updated successfully.',
                $row->fresh()->load(['product', 'variant'])->toApiArray()
            );
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // ═════════════════════════════════════════════════════
    // 5. DELETE
    // ═════════════════════════════════════════════════════
    public function delete(Request $request)
    {
        try {
            $authUser = $request->user();

            $id = (int) ($request->input('id') ?? $request->query('id') ?? 0);
            if (!$id) {
                return $this->fail('id is required.', self::VALIDATION_ERR);
            }

            $row = $this->findOwned($id, $authUser);
            if (!$row) {
                return $this->fail(
                    'Barcode not found or not under your administration.',
                    self::NOT_FOUND
                );
            }

            $row->delete();

            return $this->ok('Barcode deleted successfully.', ['id' => $id]);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }
}