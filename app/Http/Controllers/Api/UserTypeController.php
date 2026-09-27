<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserType;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class UserTypeController extends Controller
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

    // ─── Scope helper ────────────────────────────────────
    /**
     * Apply a scope filter for company_id. If $companyId is null,
     * restrict to rows WHERE company_id IS NULL (global scope).
     */
    private function scopeByCompany($query, ?int $companyId)
    {
        return $companyId === null
            ? $query->whereNull('company_id')
            : $query->where('company_id', $companyId);
    }

    // ═════════════════════════════════════════════════════
    // 1. LIST
    // GET /api/UserType/get_all
    //   ?company_id=X   → only types for a given company
    //   ?global=1       → only types with company_id IS NULL
    //   ?only_mine=1    → only rows created_by = current user
    // ═════════════════════════════════════════════════════
    public function getAll(Request $request)
    {
        try {
            $authUser  = $request->user();
            $companyId = $request->query('company_id');
            $global    = (int) $request->query('global', 0) === 1;
            $onlyMine  = (int) $request->query('only_mine', 0) === 1;

            $query = UserType::with('company')->orderBy('name');

            if ($global) {
                $query->whereNull('company_id');
            } elseif ($companyId !== null && $companyId !== '') {
                $query->where('company_id', (int) $companyId);
            }

            if ($onlyMine) {
                $query->where('created_by', $authUser->id);
            }

            $types = $query->get()->map(fn ($t) => $t->toApiArray());

            return $this->ok('User types fetched successfully.', $types);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // ═════════════════════════════════════════════════════
    // 2. GET BY ID
    // GET /api/UserType/get_by_id?id=X
    // ═════════════════════════════════════════════════════
    public function getById(Request $request)
    {
        try {
            $id = (int) $request->query('id', 0);
            if (!$id) {
                return $this->fail('Id is required.', self::VALIDATION_ERR);
            }

            $type = UserType::with('company')->find($id);
            if (!$type) {
                return $this->fail('User type not found.', self::NOT_FOUND);
            }

            return $this->ok('User type fetched successfully.', $type->toApiArray());
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // ═════════════════════════════════════════════════════
    // 3. CREATE
    // POST /api/UserType/save
    //
    // Body:
    //   name        (required, max 100)
    //   company_id  (nullable, must exist in companies)
    //
    // Duplicate rule: name must be unique within the same company scope.
    // Passing no company_id (or null) creates a "global" type.
    // ═════════════════════════════════════════════════════
    public function save(Request $request)
    {
        try {
            $v = Validator::make($request->all(), [
                'name'       => 'required|string|max:100',
                'company_id' => 'nullable|integer|exists:companies,id',
            ]);

            if ($v->fails()) {
                return $this->fail($v->errors()->first(), self::VALIDATION_ERR);
            }

            $name      = trim($request->input('name'));
            $companyId = $request->filled('company_id')
                ? (int) $request->input('company_id')
                : null;

            // Duplicate check within the same scope
            $dup = $this->scopeByCompany(
                UserType::where('name', $name),
                $companyId
            )->exists();

            if ($dup) {
                return $this->fail(
                    $companyId
                        ? 'User type name already exists for this company.'
                        : 'A global user type with this name already exists.',
                    self::DUPLICATE
                );
            }

            $type = UserType::create([
                'name'       => $name,
                'company_id' => $companyId,
                'created_by' => auth()->id(),
            ]);

            return $this->ok('User type created successfully.', [
                'id' => $type->id,
            ]);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // ═════════════════════════════════════════════════════
    // 4. UPDATE
    // PUT /api/UserType/update
    // Body: { id, ...optional fields }
    //
    // Sending company_id: null explicitly moves the type to the global scope.
    // Omitting company_id leaves it unchanged.
    // ═════════════════════════════════════════════════════
    public function update(Request $request)
    {
        try {
            $v = Validator::make($request->all(), [
                'id'         => 'required|integer|min:1',
                'name'       => 'sometimes|string|max:100',
                'company_id' => 'sometimes|nullable|integer|exists:companies,id',
            ]);

            if ($v->fails()) {
                return $this->fail($v->errors()->first(), self::VALIDATION_ERR);
            }

            $id = (int) $request->input('id');
            $type = UserType::find($id);
            if (!$type) {
                return $this->fail('User type not found.', self::NOT_FOUND);
            }

            $data = [];

            if ($request->filled('name')) {
                $data['name'] = trim($request->input('name'));
            }

            // company_id can be explicitly set to null (global) or to a number.
            // Distinguish "not sent" from "sent as null".
            if ($request->has('company_id')) {
                $data['company_id'] = $request->input('company_id')
                    ? (int) $request->input('company_id')
                    : null;
            }

            // Duplicate check using effective values
            $effectiveName      = $data['name'] ?? $type->name;
            $effectiveCompanyId = array_key_exists('company_id', $data)
                ? $data['company_id']
                : $type->company_id;

            $dup = $this->scopeByCompany(
                UserType::where('name', $effectiveName)->where('id', '!=', $id),
                $effectiveCompanyId
            )->exists();

            if ($dup) {
                return $this->fail(
                    $effectiveCompanyId
                        ? 'User type name already exists for this company.'
                        : 'A global user type with this name already exists.',
                    self::DUPLICATE
                );
            }

            if (empty($data)) {
                return $this->fail('No fields to update.', self::VALIDATION_ERR);
            }

            $type->update($data);

            return $this->ok(
                'User type updated successfully.',
                $type->fresh()->load('company')->toApiArray()
            );
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // ═════════════════════════════════════════════════════
    // 5. DELETE
    // DELETE /api/UserType/delete
    // Body or query: { id }
    // Guard: refuse if any user is still assigned.
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

            $type = UserType::find($id);
            if (!$type) {
                return $this->fail('User type not found.', self::NOT_FOUND);
            }

            // Guard: refuse if any users are still assigned to this type.
            $inUse = User::where('user_type_id', $id)->exists();
            if ($inUse) {
                return $this->fail(
                    'Cannot delete — users are still assigned to this user type.',
                    self::FORBIDDEN
                );
            }

            $type->delete();

            return $this->ok('User type deleted successfully.');
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }
}