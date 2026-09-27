<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MenuAllocation;
use App\Models\MenuInfo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class MenuAllocationController extends Controller
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
     * 0 or null → NULL in the DB, so the unique index
     * and lookups work correctly for user-scope and type-scope.
     */
    private function scopeIds($userId, $typeId): array
    {
        $u = (int) ($userId ?? 0);
        $t = (int) ($typeId ?? 0);
        return [
            'user_info_id' => $u === 0 ? null : $u,
            'user_type_id' => $t === 0 ? null : $t,
        ];
    }

    // ─── Tree helper ─────────────────────────────────────
    /**
     * Return the given menu id plus every descendant id
     * (submenu + access, recursively). Cycle-safe.
     */
    private function collectMenuTreeIds(int $rootId): array
    {
        $ids     = [$rootId];
        $queue   = [$rootId];
        $visited = [$rootId => true];
        $safety  = 0;

        while (!empty($queue) && $safety++ < 1000) {
            $children = MenuInfo::whereIn('parent_id', $queue)
                ->pluck('id')
                ->toArray();

            $queue = [];

            foreach ($children as $childId) {
                $childId = (int) $childId;

                if (!isset($visited[$childId])) {
                    $visited[$childId] = true;
                    $ids[] = $childId;
                    $queue[] = $childId;
                }
            }
        }

        return $ids;
    }

    // ═════════════════════════════════════════════════════
    // 1. ASSIGN MENU
    // POST /api/MenuAllocation/typewise_menu_allocation
    //
    // Body:
    //   tbl_menuinfo_id  (required)
    //   tbl_userinfo_id  (nullable) — user id, or 0 when assigning by type
    //   tbl_type_id      (nullable) — user_type id, or 0 when assigning by user
    //   priority         (optional)
    // ═════════════════════════════════════════════════════
    public function assignMenu(Request $request)
    {
        try {
            $v = Validator::make($request->all(), [
                'tbl_menuinfo_id' => 'required|integer|exists:menu_infos,id',
                'tbl_userinfo_id' => 'nullable|integer|min:0',
                'tbl_type_id'     => 'nullable|integer|min:0',
                'priority'        => 'nullable|integer|min:1',
            ]);

            if ($v->fails()) {
                return $this->fail($v->errors()->first(), self::VALIDATION_ERR);
            }

            $menuId   = (int) $request->input('tbl_menuinfo_id');
            $userId   = (int) ($request->input('tbl_userinfo_id') ?? 0);
            $typeId   = (int) ($request->input('tbl_type_id') ?? 0);
            $priority = (int) $request->input('priority', 1);

            if (!$userId && !$typeId) {
                return $this->fail(
                    'Provide either a user or a user type.',
                    self::VALIDATION_ERR
                );
            }
            if ($userId && $typeId) {
                return $this->fail(
                    'Provide either a user or a user type, not both.',
                    self::VALIDATION_ERR
                );
            }

            $scope = $this->scopeIds($userId, $typeId);

            $existing = MenuAllocation::where('menu_info_id', $menuId)
                ->where('user_info_id', $scope['user_info_id'])
                ->where('user_type_id', $scope['user_type_id'])
                ->first();

            if ($existing) {
                if ($existing->status === 'active') {
                    return $this->fail(
                        'This menu is already assigned to this scope.',
                        self::DUPLICATE
                    );
                }

                $existing->update([
                    'status'   => 'active',
                    'priority' => $priority,
                ]);

                return $this->ok('Menu assignment reactivated.', [
                    'id' => $existing->id,
                ]);
            }

            $alloc = MenuAllocation::create([
                'menu_info_id' => $menuId,
                'user_info_id' => $scope['user_info_id'],
                'user_type_id' => $scope['user_type_id'],
                'priority'     => $priority,
                'status'       => 'active',
                'created_by'   => auth()->id(),
            ]);

            return $this->ok('Menu assigned successfully.', [
                'id' => $alloc->id,
            ]);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // ═════════════════════════════════════════════════════
    // 2. UNASSIGN MENU (cascades to all descendants)
    // DELETE /api/MenuAllocation/remove_menu_allocation
    //
    // Body (or query):
    //   tbl_menuinfo_id  (required)
    //   tbl_userinfo_id  (nullable) — user id, or 0
    //   tbl_type_id      (nullable) — user_type id, or 0
    //
    // Deactivates the target menu AND every descendant
    // (submenu + access) in the same scope.
    // ═════════════════════════════════════════════════════
    public function unassignMenu(Request $request)
    {
        try {
            $menuId = (int) (
                $request->input('tbl_menuinfo_id')
                ?? $request->query('tbl_menuinfo_id')
                ?? 0
            );
            $userId = (int) (
                $request->input('tbl_userinfo_id')
                ?? $request->query('tbl_userinfo_id')
                ?? 0
            );
            $typeId = (int) (
                $request->input('tbl_type_id')
                ?? $request->query('tbl_type_id')
                ?? 0
            );

            if (!$menuId) {
                return $this->fail('Menu id is required.', self::VALIDATION_ERR);
            }

            if (!$userId && !$typeId) {
                return $this->fail(
                    'Provide either a user or a user type.',
                    self::VALIDATION_ERR
                );
            }
            if ($userId && $typeId) {
                return $this->fail(
                    'Provide either a user or a user type, not both.',
                    self::VALIDATION_ERR
                );
            }

            $scope   = $this->scopeIds($userId, $typeId);
            $menuIds = $this->collectMenuTreeIds($menuId);

            $affected = MenuAllocation::whereIn('menu_info_id', $menuIds)
                ->where('user_info_id', $scope['user_info_id'])
                ->where('user_type_id', $scope['user_type_id'])
                ->where('status', 'active')
                ->update(['status' => 'inactive']);

            if (!$affected) {
                return $this->fail('Allocation not found.', self::NOT_FOUND);
            }

            $msg = $affected === 1
                ? 'Menu unassigned successfully.'
                : "Menu unassigned successfully (including {$affected} item(s)).";

            return $this->ok($msg, ['deactivated' => $affected]);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // ═════════════════════════════════════════════════════
    // 3. LIST ALLOCATIONS
    // GET /api/MenuAllocation/get_all
    //   ?menu_info_id=X
    //   ?user_info_id=X
    //   ?user_type_id=X
    //   ?status=active|inactive
    // ═════════════════════════════════════════════════════
    public function getAll(Request $request)
    {
        try {
            $query = MenuAllocation::with(['menu', 'user', 'userType'])
                ->orderBy('menu_info_id')
                ->orderBy('priority');

            if ($request->filled('menu_info_id')) {
                $query->where('menu_info_id', (int) $request->input('menu_info_id'));
            }
            if ($request->filled('user_info_id')) {
                $query->where('user_info_id', (int) $request->input('user_info_id'));
            }
            if ($request->filled('user_type_id')) {
                $query->where('user_type_id', (int) $request->input('user_type_id'));
            }
            if ($request->filled('status')) {
                $query->where('status', $request->input('status'));
            }

            $rows = $query->get()->map(fn ($a) => $a->toApiArray());

            return $this->ok('Allocations fetched successfully.', $rows);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }
}