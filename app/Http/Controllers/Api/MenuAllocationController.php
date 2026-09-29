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

    // ─── Tree helpers ────────────────────────────────────
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

    /**
     * Given a set of menu ids, add every ancestor id so the nested
     * tree renders correctly. Returns a unique, flat array.
     */
    private function includeAncestors(array $ids): array
    {
        if (empty($ids)) return [];

        $result = array_map('intval', $ids);
        $queue  = $result;
        $safety = 0;

        while (!empty($queue) && $safety++ < 1000) {
            $parents = MenuInfo::whereIn('id', array_unique($queue))
                ->whereNotNull('parent_id')
                ->pluck('parent_id')
                ->map(fn ($p) => (int) $p)
                ->unique()
                ->toArray();

            $queue = [];

            foreach ($parents as $pid) {
                if (!in_array($pid, $result, true)) {
                    $result[] = $pid;
                    $queue[]  = $pid;
                }
            }
        }

        return array_values(array_unique($result));
    }

    /**
     * Build a nested menu tree from a flat collection.
     * Each node: { id, menu, icon, menu_url, type, status, menu_order,
     *              parent_id, submenu: [], access: [] }
     */
    private function buildMenuTree($flatRows): array
    {
        $byId  = [];
        $roots = [];

        foreach ($flatRows as $row) {
            $id   = (int) $row->id;
            $item = $row instanceof MenuInfo ? $row->toApiArray() : (array) $row;

            $item['submenu'] = [];
            $item['access']  = [];
            $byId[$id] = $item;
        }

        foreach ($flatRows as $row) {
            $id  = (int) $row->id;
            $pid = (int) ($byId[$id]['parent_id'] ?? 0);

            if ($pid === 0 || !isset($byId[$pid])) {
                $roots[] = &$byId[$id];
                continue;
            }

            $isAccess = strtolower($byId[$id]['type'] ?? '') === 'access';

            if ($isAccess) {
                $byId[$pid]['access'][] = &$byId[$id];
            } else {
                $byId[$pid]['submenu'][] = &$byId[$id];
            }
        }

        return $roots;
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

    // ═════════════════════════════════════════════════════
    // 4. GET ASSIGNED MENUS (for the current user)
    // GET /api/MenuAllocation/get_assigned_menus
    //   ?user_info_id=X   (optional — defaults to the caller's id)
    //   ?user_type_id=X   (optional — defaults to the caller's user_type_id)
    //
    // Returns the menus assigned to the given user / user type
    // as a nested tree, ready for the sidebar.
    //
    // Rules:
    //   • A caller can only request their own id/type
    //     (unless they are a super admin).
    //   • Menus assigned to the user_type apply to every user with that type.
    //   • A menu assigned directly to the user takes precedence.
    // ═════════════════════════════════════════════════════
    public function getAssignedMenus(Request $request)
    {
        try {
            $authUser     = $request->user();
            $isSuperAdmin = $authUser->hasRole('super_admin');

            // Resolve the target scope
            $userId = (int) (
                $request->query('user_info_id')
                ?: $authUser->id
            );
            $typeId = (int) (
                $request->query('user_type_id')
                ?: ($authUser->user_type_id ?? 0)
            );

            // Non-super-admins can only request their own scope
            if (!$isSuperAdmin && $userId !== (int) $authUser->id) {
                return $this->fail(
                    'You can only view menus assigned to yourself.',
                    self::FORBIDDEN
                );
            }

            // Fetch active allocations for this user OR this user type
            $query = MenuAllocation::where('status', 'active')
                ->where(function ($q) use ($userId, $typeId) {
                    if ($userId) {
                        $q->orWhere('user_info_id', $userId);
                    }
                    if ($typeId) {
                        $q->orWhere('user_type_id', $typeId);
                    }
                })
                ->orderBy('priority')
                ->orderBy('menu_info_id');

            $menuIds = $query->pluck('menu_info_id')
                ->unique()
                ->values()
                ->toArray();

            if (empty($menuIds)) {
                return $this->ok('No menus assigned.', []);
            }

            // Ensure every ancestor of every assigned menu is included
            $menuIds = $this->includeAncestors($menuIds);

            // Load the menus as a flat list and build the tree
            $rows = MenuInfo::whereIn('id', $menuIds)
                ->orderByRaw('COALESCE(parent_id, 0) ASC')
                ->orderBy('menu_order')
                ->orderBy('id')
                ->get();

            $tree = $this->buildMenuTree($rows);

            return $this->ok('Assigned menus fetched successfully.', $tree);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }
        // ═════════════════════════════════════════════════════
    // 5. GET MY MENUS (for the authenticated user)
    // GET /api/MenuAllocation/get_my_menus
    //
    // No query params needed — everything is derived from
    // the bearer token / authenticated user.
    //
    // Rules:
    //   • Menus assigned directly to the user take precedence
    //     over menus assigned to the user's type.
    //   • Every ancestor of an assigned menu is included so
    //     the nested tree renders correctly.
    //   • Only active allocations are considered.
    // ═════════════════════════════════════════════════════
    public function getMyMenus(Request $request)
    {
        try {
            $authUser = $request->user();

            if (!$authUser) {
                return $this->fail('Unauthenticated.', self::FORBIDDEN);
            }

            $userId = (int) $authUser->id;
            $typeId = (int) ($authUser->user_type_id ?? 0);

            // Active allocations for this user OR this user type
            $allocations = MenuAllocation::where('status', 'active')
                ->where(function ($q) use ($userId, $typeId) {
                    $q->where('user_info_id', $userId);

                    if ($typeId) {
                        $q->orWhere('user_type_id', $typeId);
                    }
                })
                ->orderBy('priority')
                ->orderBy('menu_info_id')
                ->get();

            if ($allocations->isEmpty()) {
                return $this->ok('No menus assigned.', []);
            }

            // Direct user assignments win over type-wide ones.
            // Start with user-specific allocations, then add
            // type-scoped ones that don't duplicate.
            $userSpecific = $allocations
                ->where('user_info_id', $userId)
                ->pluck('menu_info_id')
                ->map(fn ($v) => (int) $v)
                ->unique()
                ->values()
                ->toArray();

            $typeScoped = $typeId
                ? $allocations
                    ->where('user_type_id', $typeId)
                    ->whereNull('user_info_id') // pure type-level only
                    ->pluck('menu_info_id')
                    ->map(fn ($v) => (int) $v)
                    ->unique()
                    ->values()
                    ->toArray()
                : [];

            $menuIds = array_values(array_unique(array_merge($userSpecific, $typeScoped)));

            if (empty($menuIds)) {
                return $this->ok('No menus assigned.', []);
            }

            // Include every ancestor so the nested tree renders correctly
            $menuIds = $this->includeAncestors($menuIds);

            $rows = MenuInfo::whereIn('id', $menuIds)
                ->orderByRaw('COALESCE(parent_id, 0) ASC')
                ->orderBy('menu_order')
                ->orderBy('id')
                ->get();

            $tree = $this->buildMenuTree($rows);

            return $this->ok('My menus fetched successfully.', $tree);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }
        // ═════════════════════════════════════════════════════
    // 6. GET ASSIGNABLE MENUS (for delegated admins)
    // GET /api/MenuAllocation/get_assignable_menus
    //
    // Returns the menus the authenticated user can delegate
    // to users / user types they created.
    //
    // Rules:
    //   • Super admins get every menu.
    //   • Regular users get exactly the menus assigned to them
    //     (direct + type-scoped + ancestors), same as getMyMenus.
    // ═════════════════════════════════════════════════════
    public function getAssignableMenus(Request $request)
    {
        try {
            $authUser = $request->user();

            if (!$authUser) {
                return $this->fail('Unauthenticated.', self::FORBIDDEN);
            }

            // Super admins can delegate every menu
            if ($authUser->hasRole('super_admin')) {
                $rows = MenuInfo::orderByRaw('COALESCE(parent_id, 0) ASC')
                    ->orderBy('menu_order')
                    ->orderBy('id')
                    ->get();

                $tree = $this->buildMenuTree($rows);
                return $this->ok('Assignable menus fetched successfully.', $tree);
            }

            // Regular users: reuse the same logic as getMyMenus
            $userId = (int) $authUser->id;
            $typeId = (int) ($authUser->user_type_id ?? 0);

            $allocations = MenuAllocation::where('status', 'active')
                ->where(function ($q) use ($userId, $typeId) {
                    $q->where('user_info_id', $userId);
                    if ($typeId) {
                        $q->orWhere('user_type_id', $typeId);
                    }
                })
                ->get();

            if ($allocations->isEmpty()) {
                return $this->ok('No menus assigned.', []);
            }

            $userSpecific = $allocations
                ->where('user_info_id', $userId)
                ->pluck('menu_info_id')
                ->map(fn ($v) => (int) $v)
                ->unique()
                ->values()
                ->toArray();

            $typeScoped = $typeId
                ? $allocations
                    ->where('user_type_id', $typeId)
                    ->whereNull('user_info_id')
                    ->pluck('menu_info_id')
                    ->map(fn ($v) => (int) $v)
                    ->unique()
                    ->values()
                    ->toArray()
                : [];

            $menuIds = array_values(array_unique(array_merge($userSpecific, $typeScoped)));

            if (empty($menuIds)) {
                return $this->ok('No menus assigned.', []);
            }

            $menuIds = $this->includeAncestors($menuIds);

            $rows = MenuInfo::whereIn('id', $menuIds)
                ->orderByRaw('COALESCE(parent_id, 0) ASC')
                ->orderBy('menu_order')
                ->orderBy('id')
                ->get();

            $tree = $this->buildMenuTree($rows);

            return $this->ok('Assignable menus fetched successfully.', $tree);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }
}