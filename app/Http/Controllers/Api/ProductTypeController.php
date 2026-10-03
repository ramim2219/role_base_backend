<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ProductTypeController extends Controller
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

    private function findOwned(int $id, $authUser): ?ProductType
    {
        return ProductType::where('id', $id)
            ->where('created_by', $authUser->id)
            ->first();
    }

    // ═════════════════════════════════════════════════════
    // 1. LIST
    // GET /api/ProductType/get_all
    //   ?company_id=X
    //   ?status=1|0
    //   ?search=simple
    // ═════════════════════════════════════════════════════
    public function getAll(Request $request)
    {
        try {
            $authUser = $request->user();

            $query = ProductType::where('created_by', $authUser->id)
                ->orderBy('name');

            if ($request->filled('company_id')) {
                $query->where('company_id', (int) $request->input('company_id'));
            }
            if ($request->filled('status')) {
                $query->where('status', (int) $request->input('status'));
            }
            if ($request->filled('search')) {
                $term = '%' . $request->input('search') . '%';
                $query->where('name', 'like', $term);
            }

            $rows = $query->get()->map(fn ($t) => $t->toApiArray());

            return $this->ok('Product types fetched successfully.', $rows);
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
            $type = $this->findOwned($id, $authUser);

            if (!$type) {
                return $this->fail(
                    'Product type not found or not under your administration.',
                    self::NOT_FOUND
                );
            }

            return $this->ok('Product type fetched successfully.', $type->toApiArray());
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // ═════════════════════════════════════════════════════
    // 3. CREATE
    // POST /api/ProductType/save
    // ═════════════════════════════════════════════════════
    public function save(Request $request)
    {
        try {
            $authUser = $request->user();

            $v = Validator::make($request->all(), [
                'company_id'  => 'required|integer|exists:companies,id',
                'name'        => 'required|string|max:100',
                'description' => 'nullable|string',
                'status'      => 'nullable|integer|in:0,1',
            ]);

            if ($v->fails()) {
                return $this->fail($v->errors()->first(), self::VALIDATION_ERR);
            }

            $companyId = (int) $request->input('company_id');
            $name      = trim($request->input('name'));

            $dup = ProductType::where('company_id', $companyId)
                ->where('name', $name)
                ->exists();

            if ($dup) {
                return $this->fail(
                    'A product type with this name already exists in this company.',
                    self::DUPLICATE
                );
            }

            $type = ProductType::create([
                'company_id'  => $companyId,
                'name'        => $name,
                'description' => $request->input('description'),
                'status'      => (int) $request->input('status', 1),
                'created_by'  => $authUser->id,
            ]);

            return $this->ok('Product type created successfully.', ['id' => $type->id]);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // ═════════════════════════════════════════════════════
    // 4. UPDATE
    // PUT /api/ProductType/update
    // ═════════════════════════════════════════════════════
    public function update(Request $request)
    {
        try {
            $authUser = $request->user();
            $id       = (int) $request->input('id');

            if (!$id) {
                return $this->fail('id is required.', self::VALIDATION_ERR);
            }

            $type = $this->findOwned($id, $authUser);
            if (!$type) {
                return $this->fail(
                    'Product type not found or not under your administration.',
                    self::NOT_FOUND
                );
            }

            $v = Validator::make($request->all(), [
                'company_id'  => 'sometimes|integer|exists:companies,id',
                'name'        => 'sometimes|string|max:100',
                'description' => 'sometimes|nullable|string',
                'status'      => 'sometimes|integer|in:0,1',
            ]);

            if ($v->fails()) {
                return $this->fail($v->errors()->first(), self::VALIDATION_ERR);
            }

            $data = [];
            if ($request->filled('company_id')) $data['company_id'] = (int) $request->input('company_id');
            if ($request->filled('name'))       $data['name']       = trim($request->input('name'));
            if ($request->has('description'))   $data['description'] = $request->input('description');
            if ($request->has('status'))        $data['status']      = (int) $request->input('status');

            $effCompanyId = $data['company_id'] ?? $type->company_id;
            $effName      = $data['name'] ?? $type->name;

            $dup = ProductType::where('company_id', $effCompanyId)
                ->where('name', $effName)
                ->where('id', '!=', $id)
                ->exists();

            if ($dup) {
                return $this->fail(
                    'A product type with this name already exists in this company.',
                    self::DUPLICATE
                );
            }

            if (empty($data)) {
                return $this->fail('No fields to update.', self::VALIDATION_ERR);
            }

            $type->update($data);

            return $this->ok('Product type updated successfully.', $type->fresh()->toApiArray());
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // ═════════════════════════════════════════════════════
    // 5. DELETE
    // DELETE /api/ProductType/delete
    // ═════════════════════════════════════════════════════
    public function delete(Request $request)
    {
        try {
            $authUser = $request->user();

            $id = (int) ($request->input('id') ?? $request->query('id') ?? 0);
            if (!$id) {
                return $this->fail('id is required.', self::VALIDATION_ERR);
            }

            $type = $this->findOwned($id, $authUser);
            if (!$type) {
                return $this->fail(
                    'Product type not found or not under your administration.',
                    self::NOT_FOUND
                );
            }

            // Block delete if products still reference this type
            $inUse = \App\Models\Product::where('product_type_id', $id)->exists();
            if ($inUse) {
                return $this->fail(
                    'Cannot delete — products are still using this type.',
                    self::FORBIDDEN
                );
            }

            $type->delete();

            return $this->ok('Product type deleted successfully.', ['id' => $id]);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }
}