<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MenuInfo;
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
     * Recursive delete — deletes menu and all descendants.
     * Works whether or not FK cascade is enabled.
     */
    private function deleteMenuRecursive(int $id): void
    {
        // 1. Recurse into children
        $childIds = MenuInfo::where('parent_id', $id)->pluck('id')->toArray();
        foreach ($childIds as $childId) {
            $this->deleteMenuRecursive((int) $childId);
        }

        // 2. Delete the menu
        MenuInfo::where('id', $id)->delete();
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
    // Cascades to children.
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
    // 5. VIEW MENUS BY ID
    // GET /api/Menu/edit_menu?menu_id=X
    //
    // Returns: [ { menu } ] — array with 1 element
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

            return $this->ok('Menu fetched successfully.', [$menu->toApiArray()]);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }
}