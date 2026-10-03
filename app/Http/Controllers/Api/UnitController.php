<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Unit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class UnitController extends Controller
{
    private const OK             = 1200;
    private const NOT_FOUND      = 2001;
    private const FORBIDDEN      = 3001;
    private const DUPLICATE      = 3002;
    private const VALIDATION_ERR = 4000;
    private const SERVER_ERR     = 5000;

    // ─── Response helpers ────────────────────────────────
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

    // ─── Ownership guard ─────────────────────────────────
    private function findOwnedUnit(int $id, $authUser): ?Unit
    {
        return Unit::where('id', $id)
            ->where('created_by', $authUser->id)
            ->first();
    }

    // ═════════════════════════════════════════════════════
    // 1. GET ALL (my units)
    // GET /api/Unit/get_all
    //   ?company_id=X
    //   ?unit_type=weight|volume|...
    //   ?status=1|0
    //   ?search=kg
    // ═════════════════════════════════════════════════════
    public function getAll(Request $request)
    {
        try {
            $authUser = $request->user();

            $query = Unit::with('company')
                ->where('created_by', $authUser->id)
                ->orderBy('name');

            if ($request->filled('company_id')) {
                $query->where('company_id', (int) $request->input('company_id'));
            }
            if ($request->filled('unit_type')) {
                $query->where('unit_type', $request->input('unit_type'));
            }
            if ($request->filled('status')) {
                $query->where('status', (int) $request->input('status'));
            }
            if ($request->filled('search')) {
                $term = '%' . $request->input('search') . '%';
                $query->where(function ($q) use ($term) {
                    $q->where('name', 'like', $term)
                      ->orWhere('short_name', 'like', $term);
                });
            }

            $rows = $query->get()->map(fn ($u) => $u->toApiArray());

            return $this->ok('Units fetched successfully.', $rows);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // ═════════════════════════════════════════════════════
    // 2. GET BY COMPANY
    // GET /api/Unit/get_by_company?company_id=X
    // ═════════════════════════════════════════════════════
    public function getByCompany(Request $request)
    {
        try {
            $authUser  = $request->user();
            $companyId = (int) $request->query('company_id');

            if (!$companyId) {
                return $this->fail('company_id is required.', self::VALIDATION_ERR);
            }

            $rows = Unit::with('company')
                ->where('company_id', $companyId)
                ->where('created_by', $authUser->id)
                ->orderBy('name')
                ->get()
                ->map(fn ($u) => $u->toApiArray());

            return $this->ok('Units fetched successfully.', $rows);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // ═════════════════════════════════════════════════════
    // 3. GET BY ID
    // GET /api/Unit/get_by_id?id=X
    // ═════════════════════════════════════════════════════
    public function getById(Request $request)
    {
        try {
            $id = (int) $request->query('id', 0);
            if (!$id) {
                return $this->fail('id is required.', self::VALIDATION_ERR);
            }

            $authUser = $request->user();
            $unit = $this->findOwnedUnit($id, $authUser);

            if (!$unit) {
                return $this->fail(
                    'Unit not found or not under your administration.',
                    self::NOT_FOUND
                );
            }

            $unit->load('company');

            return $this->ok('Unit fetched successfully.', $unit->toApiArray());
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // ═════════════════════════════════════════════════════
    // 4. CREATE
    // POST /api/Unit/save
    //
    // Body:
    //   company_id     (required)
    //   name           (required, max 100)
    //   short_name     (required, max 20, unique per company)
    //   unit_type      (required, one of the allowed types)
    //   allow_decimal  (nullable bool)
    //   status         (nullable 0|1)
    // ═════════════════════════════════════════════════════
    public function save(Request $request)
    {
        try {
            $authUser = $request->user();

            $allowedTypes = implode(',', Unit::TYPES);

            $v = Validator::make($request->all(), [
                'company_id'    => 'required|integer|exists:companies,id',
                'name'          => 'required|string|max:100',
                'short_name'    => 'required|string|max:20',
                'unit_type'     => "required|string|in:{$allowedTypes}",
                'allow_decimal' => 'nullable|boolean',
                'status'        => 'nullable|integer|in:0,1',
            ]);

            if ($v->fails()) {
                return $this->fail($v->errors()->first(), self::VALIDATION_ERR);
            }

            $companyId  = (int) $request->input('company_id');
            $name       = trim($request->input('name'));
            $shortName  = trim($request->input('short_name'));
            $unitType   = $request->input('unit_type');

            // Same short name can't repeat inside a company
            $dup = Unit::where('company_id', $companyId)
                ->where('short_name', $shortName)
                ->exists();

            if ($dup) {
                return $this->fail(
                    'A unit with this short name already exists in this company.',
                    self::DUPLICATE
                );
            }

            $unit = Unit::create([
                'company_id'    => $companyId,
                'name'          => $name,
                'short_name'    => $shortName,
                'unit_type'     => $unitType,
                'allow_decimal' => (bool) $request->input('allow_decimal', false),
                'status'        => (int) $request->input('status', 1),
                'created_by'    => $authUser->id,
            ]);

            return $this->ok('Unit created successfully.', ['id' => $unit->id]);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // ═════════════════════════════════════════════════════
    // 5. UPDATE
    // PUT /api/Unit/update
    // ═════════════════════════════════════════════════════
    public function update(Request $request)
    {
        try {
            $authUser = $request->user();
            $id       = (int) $request->input('id');

            if (!$id) {
                return $this->fail('id is required.', self::VALIDATION_ERR);
            }

            $unit = $this->findOwnedUnit($id, $authUser);
            if (!$unit) {
                return $this->fail(
                    'Unit not found or not under your administration.',
                    self::NOT_FOUND
                );
            }

            $allowedTypes = implode(',', Unit::TYPES);

            $v = Validator::make($request->all(), [
                'company_id'    => 'sometimes|integer|exists:companies,id',
                'name'          => 'sometimes|string|max:100',
                'short_name'    => 'sometimes|string|max:20',
                'unit_type'     => "sometimes|string|in:{$allowedTypes}",
                'allow_decimal' => 'sometimes|boolean',
                'status'        => 'sometimes|integer|in:0,1',
            ]);

            if ($v->fails()) {
                return $this->fail($v->errors()->first(), self::VALIDATION_ERR);
            }

            $data = [];

            if ($request->filled('company_id')) {
                $data['company_id'] = (int) $request->input('company_id');
            }
            if ($request->filled('name')) {
                $data['name'] = trim($request->input('name'));
            }
            if ($request->filled('short_name')) {
                $data['short_name'] = trim($request->input('short_name'));
            }
            if ($request->filled('unit_type')) {
                $data['unit_type'] = $request->input('unit_type');
            }
            if ($request->has('allow_decimal')) {
                $data['allow_decimal'] = (bool) $request->input('allow_decimal');
            }
            if ($request->has('status')) {
                $data['status'] = (int) $request->input('status');
            }

            // Effective values
            $effCompanyId = $data['company_id'] ?? $unit->company_id;
            $effShortName = $data['short_name'] ?? $unit->short_name;

            $dup = Unit::where('company_id', $effCompanyId)
                ->where('short_name', $effShortName)
                ->where('id', '!=', $id)
                ->exists();

            if ($dup) {
                return $this->fail(
                    'A unit with this short name already exists in this company.',
                    self::DUPLICATE
                );
            }

            if (empty($data)) {
                return $this->fail('No fields to update.', self::VALIDATION_ERR);
            }

            $unit->update($data);

            return $this->ok(
                'Unit updated successfully.',
                $unit->fresh()->load('company')->toApiArray()
            );
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // ═════════════════════════════════════════════════════
    // 6. DELETE
    // DELETE /api/Unit/delete
    // ═════════════════════════════════════════════════════
    public function delete(Request $request)
    {
        try {
            $authUser = $request->user();

            $id = (int) (
                $request->input('id')
                ?? $request->query('id')
                ?? 0
            );

            if (!$id) {
                return $this->fail('id is required.', self::VALIDATION_ERR);
            }

            $unit = $this->findOwnedUnit($id, $authUser);
            if (!$unit) {
                return $this->fail(
                    'Unit not found or not under your administration.',
                    self::NOT_FOUND
                );
            }

            // Optional: block delete if any product is using this unit.
            // Adjust table/column names to match your products schema.
            // $inUse = \App\Models\Product::where('unit_id', $id)->exists();
            // if ($inUse) {
            //     return $this->fail('Cannot delete — products are still using this unit.', self::FORBIDDEN);
            // }

            $unit->delete();

            return $this->ok('Unit deleted successfully.', ['id' => $id]);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }
}