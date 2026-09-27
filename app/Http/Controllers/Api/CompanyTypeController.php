<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CompanyType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CompanyTypeController extends Controller
{
    // Response codes — match MenuController / UserController
    private const OK             = 1200;
    private const NOT_FOUND      = 2001;
    private const DUPLICATE      = 3002;
    private const VALIDATION_ERR = 4000;
    private const SERVER_ERR     = 5000;

    // ═════════════════════════════════════════════════════
    // RESPONSE HELPERS
    // ═════════════════════════════════════════════════════

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

    // ═════════════════════════════════════════════════════
    // 1. LIST
    // GET /api/CompanyType/get_all
    // ═════════════════════════════════════════════════════
    public function getAll(Request $request)
    {
        try {
            $types = CompanyType::orderBy('name')
                ->get()
                ->map(fn ($t) => $t->toApiArray());

            return $this->ok('Company types fetched successfully.', $types);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // ═════════════════════════════════════════════════════
    // 2. GET BY ID
    // GET /api/CompanyType/get_by_id?id=X
    // ═════════════════════════════════════════════════════
    public function getById(Request $request)
    {
        try {
            $id = (int) $request->query('id', 0);
            if (!$id) {
                return $this->fail('Id is required.', self::VALIDATION_ERR);
            }

            $type = CompanyType::find($id);
            if (!$type) {
                return $this->fail('Company type not found.', self::NOT_FOUND);
            }

            return $this->ok('Company type fetched successfully.', $type->toApiArray());
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // ═════════════════════════════════════════════════════
    // 3. CREATE
    // POST /api/CompanyType/save
    // Body: { name }
    // ═════════════════════════════════════════════════════
    public function save(Request $request)
    {
        try {
            $v = Validator::make($request->all(), [
                'name' => 'required|string|max:100',
            ]);

            if ($v->fails()) {
                return $this->fail($v->errors()->first(), self::VALIDATION_ERR);
            }

            $name = trim($request->input('name'));

            $dup = CompanyType::where('name', $name)->exists();
            if ($dup) {
                return $this->fail('Company type name already exists.', self::DUPLICATE);
            }

            $type = CompanyType::create([
                'name'       => $name,
                'created_by' => auth()->id(),
            ]);

            return $this->ok('Company type created successfully.', [
                'id' => $type->id,
            ]);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // ═════════════════════════════════════════════════════
    // 4. UPDATE
    // PUT /api/CompanyType/update
    // Body: { id, name }
    // ═════════════════════════════════════════════════════
    public function update(Request $request)
    {
        try {
            $v = Validator::make($request->all(), [
                'id'   => 'required|integer|min:1',
                'name' => 'required|string|max:100',
            ]);

            if ($v->fails()) {
                return $this->fail($v->errors()->first(), self::VALIDATION_ERR);
            }

            $id   = (int) $request->input('id');
            $name = trim($request->input('name'));

            $type = CompanyType::find($id);
            if (!$type) {
                return $this->fail('Company type not found.', self::NOT_FOUND);
            }

            $dup = CompanyType::where('name', $name)
                ->where('id', '!=', $id)
                ->exists();

            if ($dup) {
                return $this->fail('Company type name already exists.', self::DUPLICATE);
            }

            $type->update(['name' => $name]);

            return $this->ok('Company type updated successfully.', $type->fresh()->toApiArray());
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // ═════════════════════════════════════════════════════
    // 5. DELETE
    // DELETE /api/CompanyType/delete
    // Body or query: { id }
    // ═════════════════════════════════════════════════════
    public function delete(Request $request)
    {
        try {
            $id = (int) (
                $request->input('id')
                ?? $request->query('id')
                ?? 0
            );

            if (!$id) {
                return $this->fail('Id is required.', self::VALIDATION_ERR);
            }

            $type = CompanyType::find($id);
            if (!$type) {
                return $this->fail('Company type not found.', self::NOT_FOUND);
            }

            // Optional: block delete if companies reference this type.
            // Adjust table/column names if your schema differs.
            // $inUse = \App\Models\Company::where('company_type_id', $id)->exists();
            // if ($inUse) {
            //     return $this->fail('Cannot delete — in use by companies.', self::FORBIDDEN);
            // }

            $type->delete();

            return $this->ok('Company type deleted successfully.');
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }
}