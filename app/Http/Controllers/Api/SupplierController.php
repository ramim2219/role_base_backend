<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SupplierController extends Controller
{
    private const OK             = 1200;
    private const NOT_FOUND      = 2001;
    private const FORBIDDEN      = 3001;
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

    private function findOwned(int $id, $authUser): ?Supplier
    {
        return Supplier::where('id', $id)->where('created_by', $authUser->id)->first();
    }

    // GET /api/Supplier/get_all
    public function getAll(Request $request)
    {
        try {
            $authUser = $request->user();
            $query = Supplier::where('created_by', $authUser->id)->orderBy('name');

            if ($request->filled('company_id')) $query->where('company_id', (int) $request->input('company_id'));
            if ($request->filled('status'))     $query->where('status', (int) $request->input('status'));
            if ($request->filled('search')) {
                $term = '%' . $request->input('search') . '%';
                $query->where(function ($q) use ($term) {
                    $q->where('name', 'like', $term)
                      ->orWhere('company_name', 'like', $term)
                      ->orWhere('phone', 'like', $term)
                      ->orWhere('email', 'like', $term);
                });
            }

            return $this->ok('Suppliers fetched successfully.', $query->get()->map(fn ($s) => $s->toApiArray()));
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // GET /api/Supplier/get_by_id?id=X
    public function getById(Request $request)
    {
        try {
            $id = (int) $request->query('id', 0);
            if (!$id) return $this->fail('id is required.', self::VALIDATION_ERR);

            $authUser = $request->user();
            $supplier = $this->findOwned($id, $authUser);
            if (!$supplier) return $this->fail('Supplier not found.', self::NOT_FOUND);

            return $this->ok('Supplier fetched successfully.', $supplier->toApiArray());
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // POST /api/Supplier/save
    public function save(Request $request)
    {
        try {
            $authUser = $request->user();

            $v = Validator::make($request->all(), [
                'company_id'      => 'required|integer|exists:companies,id',
                'name'            => 'required|string|max:150',
                'company_name'    => 'nullable|string|max:200',
                'phone'           => 'nullable|string|max:30',
                'email'           => 'nullable|email|max:150',
                'address'         => 'nullable|string',
                'tax_number'      => 'nullable|string|max:50',
                'opening_balance' => 'nullable|numeric',
                'credit_limit'    => 'nullable|numeric|min:0',
                'status'          => 'nullable|integer|in:0,1',
            ]);

            if ($v->fails()) return $this->fail($v->errors()->first(), self::VALIDATION_ERR);

            $supplier = Supplier::create([
                'company_id'      => (int) $request->input('company_id'),
                'name'            => trim($request->input('name')),
                'company_name'    => $request->input('company_name'),
                'phone'           => $request->input('phone'),
                'email'           => $request->input('email'),
                'address'         => $request->input('address'),
                'tax_number'      => $request->input('tax_number'),
                'opening_balance' => (float) $request->input('opening_balance', 0),
                'credit_limit'    => $request->has('credit_limit') ? (float) $request->input('credit_limit') : null,
                'status'          => (int) $request->input('status', 1),
                'created_by'      => $authUser->id,
            ]);

            return $this->ok('Supplier created successfully.', ['id' => $supplier->id]);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // PUT /api/Supplier/update
    public function update(Request $request)
    {
        try {
            $authUser = $request->user();
            $id = (int) $request->input('id');
            if (!$id) return $this->fail('id is required.', self::VALIDATION_ERR);

            $supplier = $this->findOwned($id, $authUser);
            if (!$supplier) return $this->fail('Supplier not found.', self::NOT_FOUND);

            $v = Validator::make($request->all(), [
                'name'            => 'sometimes|string|max:150',
                'company_name'    => 'sometimes|nullable|string|max:200',
                'phone'           => 'sometimes|nullable|string|max:30',
                'email'           => 'sometimes|nullable|email|max:150',
                'address'         => 'sometimes|nullable|string',
                'tax_number'      => 'sometimes|nullable|string|max:50',
                'opening_balance' => 'sometimes|numeric',
                'credit_limit'    => 'sometimes|nullable|numeric|min:0',
                'status'          => 'sometimes|integer|in:0,1',
            ]);

            if ($v->fails()) return $this->fail($v->errors()->first(), self::VALIDATION_ERR);

            $data = [];
            foreach (['name','company_name','phone','email','address','tax_number'] as $f) {
                if ($request->has($f)) $data[$f] = $request->input($f);
            }
            if ($request->has('opening_balance')) $data['opening_balance'] = (float) $request->input('opening_balance');
            if ($request->has('credit_limit'))    $data['credit_limit']    = $request->input('credit_limit') !== null ? (float) $request->input('credit_limit') : null;
            if ($request->has('status'))          $data['status']          = (int) $request->input('status');

            if (empty($data)) return $this->fail('No fields to update.', self::VALIDATION_ERR);

            $supplier->update($data);

            return $this->ok('Supplier updated successfully.', $supplier->fresh()->toApiArray());
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // DELETE /api/Supplier/delete
    public function delete(Request $request)
    {
        try {
            $authUser = $request->user();
            $id = (int) ($request->input('id') ?? $request->query('id') ?? 0);
            if (!$id) return $this->fail('id is required.', self::VALIDATION_ERR);

            $supplier = $this->findOwned($id, $authUser);
            if (!$supplier) return $this->fail('Supplier not found.', self::NOT_FOUND);

            $supplier->delete();
            return $this->ok('Supplier deleted successfully.', ['id' => $id]);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }
}