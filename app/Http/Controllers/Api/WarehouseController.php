<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class WarehouseController extends Controller
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

    private function findOwned(int $id, $authUser): ?Warehouse
    {
        return Warehouse::where('id', $id)->where('created_by', $authUser->id)->first();
    }

    // GET /api/Warehouse/get_all
    public function getAll(Request $request)
    {
        try {
            $authUser = $request->user();
            $query = Warehouse::with('manager')
                ->where('created_by', $authUser->id)
                ->orderBy('name');

            if ($request->filled('company_id')) $query->where('company_id', (int) $request->input('company_id'));
            if ($request->filled('status'))     $query->where('status', (int) $request->input('status'));
            if ($request->filled('search')) {
                $term = '%' . $request->input('search') . '%';
                $query->where(function ($q) use ($term) {
                    $q->where('name', 'like', $term)->orWhere('code', 'like', $term);
                });
            }

            return $this->ok('Warehouses fetched successfully.', $query->get()->map(fn ($w) => $w->toApiArray()));
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // GET /api/Warehouse/get_by_id?id=X
    public function getById(Request $request)
    {
        try {
            $id = (int) $request->query('id', 0);
            if (!$id) return $this->fail('id is required.', self::VALIDATION_ERR);

            $authUser = $request->user();
            $wh = $this->findOwned($id, $authUser);
            if (!$wh) return $this->fail('Warehouse not found.', self::NOT_FOUND);

            $wh->load('manager');

            return $this->ok('Warehouse fetched successfully.', $wh->toApiArray());
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // POST /api/Warehouse/save
    public function save(Request $request)
    {
        try {
            $authUser = $request->user();

            $v = Validator::make($request->all(), [
                'company_id'  => 'required|integer|exists:companies,id',
                'name'        => 'required|string|max:150',
                'code'        => 'required|string|max:50',
                'address'     => 'nullable|string',
                'phone'       => 'nullable|string|max:30',
                'manager_id'  => 'nullable|integer|exists:users,id',
                'status'      => 'nullable|integer|in:0,1',
            ]);

            if ($v->fails()) return $this->fail($v->errors()->first(), self::VALIDATION_ERR);

            $companyId = (int) $request->input('company_id');
            $code      = trim($request->input('code'));

            $dup = Warehouse::where('company_id', $companyId)->where('code', $code)->exists();
            if ($dup) {
                return $this->fail('A warehouse with this code already exists.', self::DUPLICATE);
            }

            $wh = Warehouse::create([
                'company_id'  => $companyId,
                'name'        => trim($request->input('name')),
                'code'        => $code,
                'address'     => $request->input('address'),
                'phone'       => $request->input('phone'),
                'manager_id'  => $request->input('manager_id'),
                'status'      => (int) $request->input('status', 1),
                'created_by'  => $authUser->id,
            ]);

            return $this->ok('Warehouse created successfully.', ['id' => $wh->id]);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // PUT /api/Warehouse/update
    public function update(Request $request)
    {
        try {
            $authUser = $request->user();
            $id = (int) $request->input('id');
            if (!$id) return $this->fail('id is required.', self::VALIDATION_ERR);

            $wh = $this->findOwned($id, $authUser);
            if (!$wh) return $this->fail('Warehouse not found.', self::NOT_FOUND);

            $v = Validator::make($request->all(), [
                'name'       => 'sometimes|string|max:150',
                'code'       => 'sometimes|string|max:50',
                'address'    => 'sometimes|nullable|string',
                'phone'      => 'sometimes|nullable|string|max:30',
                'manager_id' => 'sometimes|nullable|integer|exists:users,id',
                'status'     => 'sometimes|integer|in:0,1',
            ]);

            if ($v->fails()) return $this->fail($v->errors()->first(), self::VALIDATION_ERR);

            $data = [];
            if ($request->filled('name'))    $data['name']    = trim($request->input('name'));
            if ($request->filled('code'))    $data['code']    = trim($request->input('code'));
            if ($request->has('address'))    $data['address'] = $request->input('address');
            if ($request->has('phone'))      $data['phone']   = $request->input('phone');
            if ($request->has('manager_id')) $data['manager_id'] = $request->input('manager_id');
            if ($request->has('status'))     $data['status']  = (int) $request->input('status');

            if (isset($data['code']) && $data['code'] !== $wh->code) {
                $dup = Warehouse::where('company_id', $wh->company_id)
                    ->where('code', $data['code'])
                    ->where('id', '!=', $id)
                    ->exists();
                if ($dup) return $this->fail('A warehouse with this code already exists.', self::DUPLICATE);
            }

            if (empty($data)) return $this->fail('No fields to update.', self::VALIDATION_ERR);

            $wh->update($data);

            return $this->ok('Warehouse updated successfully.', $wh->fresh()->load('manager')->toApiArray());
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // DELETE /api/Warehouse/delete
    public function delete(Request $request)
    {
        try {
            $authUser = $request->user();
            $id = (int) ($request->input('id') ?? $request->query('id') ?? 0);
            if (!$id) return $this->fail('id is required.', self::VALIDATION_ERR);

            $wh = $this->findOwned($id, $authUser);
            if (!$wh) return $this->fail('Warehouse not found.', self::NOT_FOUND);

            $inUse = \App\Models\Stock::where('warehouse_id', $id)->where('quantity', '>', 0)->exists();
            if ($inUse) {
                return $this->fail('Cannot delete — warehouse still has stock.', self::FORBIDDEN);
            }

            $wh->delete();
            return $this->ok('Warehouse deleted successfully.', ['id' => $id]);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }
}