<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attribute;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AttributeController extends Controller
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

    private function findOwned(int $id, $authUser): ?Attribute
    {
        return Attribute::where('id', $id)
            ->where('created_by', $authUser->id)
            ->first();
    }

    // ═════════════════════════════════════════════════════
    // 1. LIST
    // GET /api/Attribute/get_all
    //   ?company_id=X
    //   ?input_type=select
    //   ?is_variant=1
    //   ?status=1|0
    //   ?search=color
    //   ?with_values=1   → include the values array per attribute
    // ═════════════════════════════════════════════════════
    public function getAll(Request $request)
    {
        try {
            $authUser = $request->user();

            $withValues = $request->boolean('with_values');

            $query = Attribute::with('company')
                ->where('created_by', $authUser->id)
                ->orderBy('display_name');

            if ($withValues) {
                $query->with('values');
            }

            if ($request->filled('company_id')) {
                $query->where('company_id', (int) $request->input('company_id'));
            }
            if ($request->filled('input_type')) {
                $query->where('input_type', $request->input('input_type'));
            }
            if ($request->filled('is_variant')) {
                $query->where('is_variant_attribute', (int) $request->boolean('is_variant'));
            }
            if ($request->filled('status')) {
                $query->where('status', (int) $request->input('status'));
            }
            if ($request->filled('search')) {
                $term = '%' . $request->input('search') . '%';
                $query->where(function ($q) use ($term) {
                    $q->where('name', 'like', $term)
                      ->orWhere('display_name', 'like', $term);
                });
            }

            $rows = $query->get()->map(fn ($a) => $a->toApiArray($withValues));

            return $this->ok('Attributes fetched successfully.', $rows);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // ═════════════════════════════════════════════════════
    // 2. GET BY ID
    // GET /api/Attribute/get_by_id?id=X&with_values=1
    // ═════════════════════════════════════════════════════
    public function getById(Request $request)
    {
        try {
            $id = (int) $request->query('id', 0);
            if (!$id) {
                return $this->fail('id is required.', self::VALIDATION_ERR);
            }

            $authUser = $request->user();
            $attr = $this->findOwned($id, $authUser);
            if (!$attr) {
                return $this->fail(
                    'Attribute not found or not under your administration.',
                    self::NOT_FOUND
                );
            }

            $attr->load(['company', 'values']);

            return $this->ok('Attribute fetched successfully.', $attr->toApiArray(true));
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // ═════════════════════════════════════════════════════
    // 3. CREATE
    // POST /api/Attribute/save
    //   company_id, name, display_name, input_type,
    //   is_variant_attribute?, status?
    // ═════════════════════════════════════════════════════
    public function save(Request $request)
    {
        try {
            $authUser = $request->user();

            $allowed = implode(',', Attribute::INPUT_TYPES);

            $v = Validator::make($request->all(), [
                'company_id'            => 'required|integer|exists:companies,id',
                'name'                  => 'required|string|max:100',
                'display_name'          => 'required|string|max:150',
                'input_type'            => "required|string|in:{$allowed}",
                'is_variant_attribute'  => 'nullable|boolean',
                'status'                => 'nullable|integer|in:0,1',
            ]);

            if ($v->fails()) {
                return $this->fail($v->errors()->first(), self::VALIDATION_ERR);
            }

            $companyId = (int) $request->input('company_id');
            $name      = strtolower(trim($request->input('name')));

            $dup = Attribute::where('company_id', $companyId)
                ->where('name', $name)
                ->exists();

            if ($dup) {
                return $this->fail(
                    'An attribute with this name already exists in this company.',
                    self::DUPLICATE
                );
            }

            $attr = Attribute::create([
                'company_id'            => $companyId,
                'name'                  => $name,
                'display_name'          => trim($request->input('display_name')),
                'input_type'            => $request->input('input_type'),
                'is_variant_attribute'  => (bool) $request->input('is_variant_attribute', false),
                'status'                => (int) $request->input('status', 1),
                'created_by'            => $authUser->id,
            ]);

            return $this->ok('Attribute created successfully.', ['id' => $attr->id]);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // ═════════════════════════════════════════════════════
    // 4. UPDATE
    // PUT /api/Attribute/update
    // ═════════════════════════════════════════════════════
    public function update(Request $request)
    {
        try {
            $authUser = $request->user();
            $id       = (int) $request->input('id');

            if (!$id) {
                return $this->fail('id is required.', self::VALIDATION_ERR);
            }

            $attr = $this->findOwned($id, $authUser);
            if (!$attr) {
                return $this->fail(
                    'Attribute not found or not under your administration.',
                    self::NOT_FOUND
                );
            }

            $allowed = implode(',', Attribute::INPUT_TYPES);

            $v = Validator::make($request->all(), [
                'company_id'            => 'sometimes|integer|exists:companies,id',
                'name'                  => 'sometimes|string|max:100',
                'display_name'          => 'sometimes|string|max:150',
                'input_type'            => "sometimes|string|in:{$allowed}",
                'is_variant_attribute'  => 'sometimes|boolean',
                'status'                => 'sometimes|integer|in:0,1',
            ]);

            if ($v->fails()) {
                return $this->fail($v->errors()->first(), self::VALIDATION_ERR);
            }

            $data = [];

            if ($request->filled('company_id')) {
                $data['company_id'] = (int) $request->input('company_id');
            }
            if ($request->filled('name')) {
                $data['name'] = strtolower(trim($request->input('name')));
            }
            if ($request->filled('display_name')) {
                $data['display_name'] = trim($request->input('display_name'));
            }
            if ($request->filled('input_type')) {
                $data['input_type'] = $request->input('input_type');
            }
            if ($request->has('is_variant_attribute')) {
                $data['is_variant_attribute'] = (bool) $request->input('is_variant_attribute');
            }
            if ($request->has('status')) {
                $data['status'] = (int) $request->input('status');
            }

            // Duplicate name check
            $effCompanyId = $data['company_id'] ?? $attr->company_id;
            $effName      = $data['name'] ?? $attr->name;

            $dup = Attribute::where('company_id', $effCompanyId)
                ->where('name', $effName)
                ->where('id', '!=', $id)
                ->exists();

            if ($dup) {
                return $this->fail(
                    'An attribute with this name already exists in this company.',
                    self::DUPLICATE
                );
            }

            if (empty($data)) {
                return $this->fail('No fields to update.', self::VALIDATION_ERR);
            }

            $attr->update($data);

            return $this->ok(
                'Attribute updated successfully.',
                $attr->fresh()->load(['company', 'values'])->toApiArray(true)
            );
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // ═════════════════════════════════════════════════════
    // 5. DELETE
    // DELETE /api/Attribute/delete
    // Cascades to attribute_values.
    // ═════════════════════════════════════════════════════
    public function delete(Request $request)
    {
        try {
            $authUser = $request->user();

            $id = (int) ($request->input('id') ?? $request->query('id') ?? 0);
            if (!$id) {
                return $this->fail('id is required.', self::VALIDATION_ERR);
            }

            $attr = $this->findOwned($id, $authUser);
            if (!$attr) {
                return $this->fail(
                    'Attribute not found or not under your administration.',
                    self::NOT_FOUND
                );
            }

            $attr->delete(); // attribute_values cascade via FK

            return $this->ok('Attribute deleted successfully.', ['id' => $id]);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }
}