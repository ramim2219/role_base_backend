<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MenuInfo;
use App\Models\MenuAllocation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class MenuController extends Controller
{
    // Response codes — match frontend MessageCode
    private const OK             = 1200;
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
    // UTILITIES
    // ═════════════════════════════════════════════════════

    private function normalizeUrl(?string $v): string
    {
        return ltrim(trim((string) $v), '/');
    }

    private function toAccessUrl(?string $name): string
    {
        $slug = strtolower(trim((string) $name));
        $slug = preg_replace('/\s+/', '_', $slug);
        return preg_replace('/[^a-z0-9_]/', '', $slug);
    }

    /**
     * Convert incoming parentId (0 or null = top-level) → DB value.
     * DB stores NULL for top-level.
     */
    private function toDbParentId($parentId): ?int
    {
        $pid = (int) ($parentId ?? 0);
        return $pid === 0 ? null : $pid;
    }

    /**
     * Convert incoming user/type ids (0 or null) → DB value.
     * DB stores NULL when the scope isn't used.
     */
    private function toDbScopeId($id): ?int
    {
        $v = (int) ($id ?? 0);
        return $v === 0 ? null : $v;
    }

    /**
     * Determine whether the given user is an admin.
     * 🔧 ADJUST to match your schema.
     */
    private function isAdmin($user): bool
    {
        if (!$user) return false;
        // Default: user_type_id = 1 → admin
        return (int) ($user->user_type_id ?? 0) === 1;
    }

    /**
     * Build nested menu tree from a flat collection.
     * Output: { id, menu, parent_id: 0, submenu: [], access: [] }
     */
    private function buildMenuTree($flatRows): array
    {
        $byId  = [];
        $roots = [];

        // Pass 1 — index every row
        foreach ($flatRows as $row) {
            $id   = (int) $row->id;
            $item = $row instanceof MenuInfo ? $row->toApiArray() : (array) $row;

            $item['submenu'] = [];
            $item['access']  = [];
            $byId[$id] = $item;
        }

        // Pass 2 — attach each to its parent
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

    /**
     * Walk up the tree and include every ancestor for the given ids.
     * Ensures nested tree renders correctly when only some children are assigned.
     */
    private function includeAncestors(array $ids): array
    {
        if (empty($ids)) return [];

        $result = $ids;
        $queue  = $ids;

        while (!empty($queue)) {
            $parents = MenuInfo::whereIn('id', array_unique($queue))
                ->whereNotNull('parent_id')
                ->pluck('parent_id')
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
     * Recursive delete — deletes menu, all descendants, and all allocations.
     * Works whether or not FK cascade is enabled.
     */
    private function deleteMenuRecursive(int $id): void
    {
        // 1. Recurse into children
        $childIds = MenuInfo::where('parent_id', $id)->pluck('id')->toArray();
        foreach ($childIds as $childId) {
            $this->deleteMenuRecursive((int) $childId);
        }

        // 2. Remove allocations
        MenuAllocation::where('menu_info_id', $id)->delete();

        // 3. Delete the menu
        MenuInfo::where('id', $id)->delete();
    }

    /**
     * Check whether the user has access to a specific menu.
     * Admins always have access.
     */
    private function userHasAccessToMenu(int $menuId, $user): bool
    {
        if (!$user) return false;
        if ($this->isAdmin($user)) return true;

        $userId = (int) $user->id;
        $typeId = (int) ($user->user_type_id ?? 0);

        return MenuAllocation::where('menu_info_id', $menuId)
            ->where('status', 'active')
            ->where(function ($q) use ($userId, $typeId) {
                if ($userId) $q->orWhere('user_info_id', $userId);
                if ($typeId) $q->orWhere('user_type_id', $typeId);
            })
            ->exists();
    }

    /**
     * Check if $candidateId is a descendant of $ancestorId.
     * Prevents cycles when moving menus.
     */
    private function isDescendantOf(int $candidateId, int $ancestorId): bool
    {
        $currentId = $candidateId;
        $safety    = 0;

        while ($currentId && $safety++ < 100) {
            if ($currentId === $ancestorId) return true;

            $parent = MenuInfo::where('id', $currentId)->value('parent_id');
            if (!$parent) return false;

            $currentId = (int) $parent;
        }

        return false;
    }

    // ═════════════════════════════════════════════════════
    // 1. SAVE MENU (create)
    // POST /api/Menu/saveMenu
    // ═════════════════════════════════════════════════════
    public function saveMenu(Request $request)
    {
        try {
            $v = Validator::make($request->all(), [
                'menuName'  => 'required|string|max:150',
                'menuUrl'   => 'nullable|string|max:255',
                'menuOrder' => 'required|integer|min:1',
                'parentId'  => 'nullable|integer|min:0',
                'type'      => 'nullable|in:Menu,Access',
                'status'    => 'nullable|in:On,Off',
                'icon'      => 'nullable|string|max:100',
                'createdBy' => 'nullable|integer',
            ]);

            if ($v->fails()) {
                return $this->fail($v->errors()->first(), self::VALIDATION_ERR);
            }

            $type      = $request->input('type', 'Menu');
            $name      = trim($request->input('menuName'));
            $menuUrl   = $type === 'Access'
                ? $this->toAccessUrl($name)
                : $this->normalizeUrl($request->input('menuUrl'));
            $parentId  = $this->toDbParentId($request->input('parentId', 0));
            $menuOrder = (int) $request->input('menuOrder', 1);

            // Duplicate order within same parent
            $dup = MenuInfo::where('parent_id', $parentId)
                ->where('menu_order', $menuOrder)
                ->exists();

            if ($dup) {
                return $this->fail(
                    "Order {$menuOrder} already exists under this parent.",
                    self::DUPLICATE
                );
            }

            $menu = MenuInfo::create([
                'menu'       => $name,
                'icon'       => $request->input('icon'),
                'menu_url'   => $menuUrl,
                'parent_id'  => $parentId,               // NULL for top-level
                'type'       => $type,
                'status'     => $request->input('status', 'On'),
                'menu_order' => $menuOrder,
                'created_by' => $request->input('createdBy') ?? auth()->id(),
            ]);

            return $this->ok('Menu created successfully.', ['id' => $menu->id]);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // ═════════════════════════════════════════════════════
    // 2. UPDATE MENU
    // PUT /api/Menu/update_menu
    // ═════════════════════════════════════════════════════
    public function updateMenu(Request $request)
    {
        try {
            $id = (int) $request->input('id', 0);
            if (!$id) {
                return $this->fail('Menu id is required.', self::VALIDATION_ERR);
            }

            $menu = MenuInfo::find($id);
            if (!$menu) {
                return $this->fail('Menu not found.', self::VALIDATION_ERR);
            }

            $type      = $request->input('type', $menu->type);
            $name      = trim($request->input('menuName', $menu->menu));
            $menuUrl   = $type === 'Access'
                ? $this->toAccessUrl($name)
                : $this->normalizeUrl($request->input('menuUrl', $menu->menu_url));
            $parentId  = $this->toDbParentId(
                $request->input('parentId', $menu->parent_id)
            );
            $menuOrder = (int) $request->input('menuOrder', $menu->menu_order);

            // Prevent self-parent
            if ($parentId === $id) {
                return $this->fail('A menu cannot be its own parent.', self::VALIDATION_ERR);
            }

            // Prevent cycles
            if ($parentId !== null && $this->isDescendantOf($parentId, $id)) {
                return $this->fail(
                    'Cannot move a menu under its own descendant.',
                    self::VALIDATION_ERR
                );
            }

            // Duplicate order (exclude self)
            $dup = MenuInfo::where('parent_id', $parentId)
                ->where('menu_order', $menuOrder)
                ->where('id', '!=', $id)
                ->exists();

            if ($dup) {
                return $this->fail(
                    "Order {$menuOrder} already exists under this parent.",
                    self::DUPLICATE
                );
            }

            $menu->update([
                'menu'       => $name,
                'menu_url'   => $menuUrl,
                'parent_id'  => $parentId,
                'type'       => $type,
                'status'     => $request->input('status', $menu->status),
                'menu_order' => $menuOrder,
                'icon'       => $request->input('icon', $menu->icon),
            ]);

            return $this->ok('Menu updated successfully.');
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // ═════════════════════════════════════════════════════
    // 3. DELETE MENU
    // DELETE /api/Menu/delete_menu?menu_id=X
    // Cascades to children, access items, and allocations.
    // ═════════════════════════════════════════════════════
    public function deleteMenu(Request $request)
    {
        try {
            $id = (int) ($request->query('menu_id') ?? $request->input('menu_id', 0));
            if (!$id) {
                return $this->fail('Menu id is required.', self::VALIDATION_ERR);
            }

            $menu = MenuInfo::find($id);
            if (!$menu) {
                return $this->fail('Menu not found.', self::VALIDATION_ERR);
            }

            // Atomic recursive delete
            DB::transaction(function () use ($id) {
                $this->deleteMenuRecursive($id);
            });

            return $this->ok('Menu deleted successfully.');
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // ═════════════════════════════════════════════════════
    // 4. VIEW ALL MENUS
    // GET /api/Menu/get_menus_by_software
    //
    //   Admin  → all menus (unless ?userinfo_id / ?usertype_id given)
    //   User   → only assigned menus (auto-filter by their id + type)
    //   ?flat=1 → flat list instead of nested tree
    // ═════════════════════════════════════════════════════
    // ═════════════════════════════════════════════════════
    // 4. VIEW ALL MENUS
    // GET /api/Menu/get_all_menus
    //
    // Returns every menu as a nested tree. No filtering.
    // ═════════════════════════════════════════════════════
    public function viewAllMenu(Request $request)
    {
        try {
            $rows = MenuInfo::query()
                ->orderByRaw('COALESCE(parent_id, 0) ASC')
                ->orderBy('menu_order')
                ->orderBy('id')
                ->get();

            return $this->ok(
                'Menus fetched successfully.',
                $this->buildMenuTree($rows)
            );
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // ═════════════════════════════════════════════════════
    // 5. VIEW MENUS BY ID (with access guard)
    // GET /api/Menu/edit_menu?menu_id=X
    //
    // Returns: [ { menu } ] — array with 1 element
    // Access: only admins or users with this menu assigned
    // ═════════════════════════════════════════════════════
    public function viewMenusById(Request $request)
    {
        try {
            $id = (int) $request->query('menu_id', 0);
            if (!$id) {
                return $this->fail('Menu id is required.', self::VALIDATION_ERR);
            }

            $menu = MenuInfo::find($id);
            if (!$menu) {
                return $this->fail('Menu not found.', self::VALIDATION_ERR);
            }

            $user = auth()->user();

            // Guard: only admins or assigned users may view
            if (!$this->userHasAccessToMenu($id, $user)) {
                return $this->fail(
                    'You do not have access to this menu.',
                    self::VALIDATION_ERR
                );
            }

            return $this->ok('Menu fetched successfully.', [$menu->toApiArray()]);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // ═════════════════════════════════════════════════════
    // 6. ASSIGN MENU (menuAllocation)
    // POST /api/Menu/typewise_menu_allocation
    //
    // Body:
    //   tbl_menuinfo_id  (required)
    //   tbl_userinfo_id  (nullable) — set this OR tbl_type_id
    //   tbl_type_id      (nullable) — set this OR tbl_userinfo_id
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
            $userId   = $this->toDbScopeId($request->input('tbl_userinfo_id', 0));
            $typeId   = $this->toDbScopeId($request->input('tbl_type_id', 0));
            $priority = (int) $request->input('priority', 1);

            if (!$userId && !$typeId) {
                return $this->fail(
                    'Either a user or a type must be provided.',
                    self::VALIDATION_ERR
                );
            }
            if ($userId && $typeId) {
                return $this->fail(
                    'Provide either user or type, not both.',
                    self::VALIDATION_ERR
                );
            }

            // Check existing (active or inactive)
            $existing = MenuAllocation::where('menu_info_id', $menuId)
                ->where('user_info_id', $userId)
                ->where('user_type_id', $typeId)
                ->first();

            if ($existing) {
                if ($existing->status === 'active') {
                    return $this->fail(
                        'This menu is already assigned.',
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
                'user_info_id' => $userId,
                'user_type_id' => $typeId,
                'priority'     => $priority,
                'status'       => 'active',
                'created_by'   => auth()->id(),
            ]);

            return $this->ok('Menu assigned successfully.', ['id' => $alloc->id]);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // ═════════════════════════════════════════════════════
    // 7. UNASSIGN MENU
    // DELETE /api/Menu/remove_menu_allocation
    // ═════════════════════════════════════════════════════
    public function unassignMenu(Request $request)
    {
        try {
            $id     = (int) ($request->input('Id') ?? $request->input('id', 0));
            $menuId = (int) $request->input('tbl_menuinfo_id', 0);
            $userId = $this->toDbScopeId($request->input('tbl_userinfo_id', 0));
            $typeId = $this->toDbScopeId($request->input('tbl_type_id', 0));

            if (!$id || !$menuId) {
                return $this->fail(
                    'Allocation id and menu id are required.',
                    self::VALIDATION_ERR
                );
            }

            $affected = MenuAllocation::where('id', $id)
                ->where('menu_info_id', $menuId)
                ->where('user_info_id', $userId)
                ->where('user_type_id', $typeId)
                ->update(['status' => 'inactive']);

            if (!$affected) {
                return $this->fail('Allocation not found.', self::VALIDATION_ERR);
            }

            return $this->ok('Menu unassigned successfully.');
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }
}