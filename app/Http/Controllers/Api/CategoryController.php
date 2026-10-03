<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class CategoryController extends Controller
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
    private function findOwnedCategory(int $id, $authUser): ?Category
    {
        return Category::where('id', $id)
            ->where('created_by', $authUser->id)
            ->first();
    }

    // ═════════════════════════════════════════════════════
    // 1. GET ALL (my categories)
    // GET /api/Category/get_all
    //   ?company_id=X   → only categories in that company
    //   ?parent_id=X    → only direct children of X (or null for top-level)
    //   ?tree=1         → return nested tree instead of flat list
    //   ?status=1|0     → filter by status
    // ═════════════════════════════════════════════════════
    public function getAll(Request $request)
    {
        try {
            $authUser = $request->user();

            $query = Category::with(['company', 'parent'])
                ->where('created_by', $authUser->id)
                ->orderBy('name');

            if ($request->filled('company_id')) {
                $query->where('company_id', (int) $request->input('company_id'));
            }
            if ($request->has('parent_id')) {
                $pid = $request->input('parent_id');
                if ($pid === '' || $pid === null || (int) $pid === 0) {
                    $query->whereNull('parent_id');
                } else {
                    $query->where('parent_id', (int) $pid);
                }
            }
            if ($request->filled('status')) {
                $query->where('status', (int) $request->input('status'));
            }

            $rows = $query->get();

            if ($request->boolean('tree')) {
                // Nest under top-level nodes
                $top = $rows->whereNull('parent_id')->values();
                $byParent = $rows->groupBy('parent_id');

                $build = function ($node) use (&$build, $byParent) {
                    $arr = $node->toApiArray();
                    $arr['children'] = ($byParent[$node->id] ?? collect())
                        ->map(fn ($c) => $build($c))
                        ->values();
                    return $arr;
                };

                $tree = $top->map(fn ($n) => $build($n))->values();
                return $this->ok('Categories fetched successfully.', $tree);
            }

            return $this->ok(
                'Categories fetched successfully.',
                $rows->map(fn ($c) => $c->toApiArray())
            );
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // ═════════════════════════════════════════════════════
    // 2. GET BY COMPANY ID
    // GET /api/Category/get_by_company?company_id=X
    //   Returns all categories in that company.
    //   Ownership rule: only categories created by the caller.
    // ═════════════════════════════════════════════════════
    public function getByCompany(Request $request)
    {
        try {
            $authUser  = $request->user();
            $companyId = (int) $request->query('company_id');

            if (!$companyId) {
                return $this->fail('company_id is required.', self::VALIDATION_ERR);
            }

            $rows = Category::with(['company', 'parent'])
                ->where('company_id', $companyId)
                ->where('created_by', $authUser->id)
                ->orderBy('name')
                ->get()
                ->map(fn ($c) => $c->toApiArray());

            return $this->ok('Categories fetched successfully.', $rows);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // ═════════════════════════════════════════════════════
    // 3. GET BY ID
    // GET /api/Category/get_by_id?id=X
    // ═════════════════════════════════════════════════════
    public function getById(Request $request)
    {
        try {
            $id = (int) $request->query('id', 0);
            if (!$id) {
                return $this->fail('id is required.', self::VALIDATION_ERR);
            }

            $authUser = $request->user();
            $category = $this->findOwnedCategory($id, $authUser);

            if (!$category) {
                return $this->fail(
                    'Category not found or not under your administration.',
                    self::NOT_FOUND
                );
            }

            $category->load(['company', 'parent']);

            return $this->ok('Category fetched successfully.', $category->toApiArray());
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // ═════════════════════════════════════════════════════
    // 4. CREATE
    // POST /api/Category/save
    //
    // Body (multipart/form-data):
    //   company_id (required)
    //   parent_id  (nullable, must be an existing category id)
    //   name       (required, unique within the same parent + company)
    //   description(nullable)
    //   image      (optional file)
    //   status     (nullable 0|1, default 1)
    // ═════════════════════════════════════════════════════
    public function save(Request $request)
    {
        try {
            $authUser = $request->user();

            $v = Validator::make($request->all(), [
                'company_id'  => 'required|integer|exists:companies,id',
                'parent_id'   => 'nullable|integer|exists:categories,id',
                'name'        => 'required|string|max:150',
                'description' => 'nullable|string',
                'image'       => 'nullable|image|mimes:jpg,jpeg,png,webp,svg|max:2048',
                'status'      => 'nullable|integer|in:0,1',
            ]);

            if ($v->fails()) {
                return $this->fail($v->errors()->first(), self::VALIDATION_ERR);
            }

            $companyId  = (int) $request->input('company_id');
            $parentId   = $request->filled('parent_id') ? (int) $request->input('parent_id') : null;
            $name       = trim($request->input('name'));

            // If a parent is provided, it must belong to the same company
            if ($parentId) {
                $parent = Category::find($parentId);
                if (!$parent || (int) $parent->company_id !== $companyId) {
                    return $this->fail(
                        'Parent category must belong to the same company.',
                        self::VALIDATION_ERR
                    );
                }
            }

            // Duplicate check within the same parent + company
            $dup = Category::where('company_id', $companyId)
                ->where('name', $name)
                ->when($parentId, fn ($q) => $q->where('parent_id', $parentId),
                                 fn ($q) => $q->whereNull('parent_id'))
                ->exists();

            if ($dup) {
                return $this->fail(
                    'A category with this name already exists here.',
                    self::DUPLICATE
                );
            }

            $imagePath = null;
            if ($request->hasFile('image')) {
                $imagePath = $request->file('image')->store('categories', 'public');
            }

            $category = Category::create([
                'company_id'  => $companyId,
                'parent_id'   => $parentId,
                'name'        => $name,
                'description' => $request->input('description'),
                'image'       => $imagePath,
                'status'      => (int) $request->input('status', 1),
                'created_by'  => $authUser->id,
            ]);

            return $this->ok('Category created successfully.', [
                'id' => $category->id,
            ]);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // ═════════════════════════════════════════════════════
    // 5. UPDATE
    // POST /api/Category/update  (use _method=PUT for files)
    //
    // Body (multipart/form-data):
    //   id (required)
    //   company_id, parent_id, name, description, status — all optional
    //   image       (optional file — replaces existing)
    //   remove_image(optional 1 — clears the image)
    // ═════════════════════════════════════════════════════
    public function update(Request $request)
    {
        try {
            $authUser = $request->user();
            $id       = (int) $request->input('id');

            if (!$id) {
                return $this->fail('id is required.', self::VALIDATION_ERR);
            }

            $category = $this->findOwnedCategory($id, $authUser);
            if (!$category) {
                return $this->fail(
                    'Category not found or not under your administration.',
                    self::NOT_FOUND
                );
            }

            $v = Validator::make($request->all(), [
                'company_id'  => 'sometimes|integer|exists:companies,id',
                'parent_id'   => 'sometimes|nullable|integer|exists:categories,id',
                'name'        => 'sometimes|string|max:150',
                'description' => 'sometimes|nullable|string',
                'image'       => 'sometimes|nullable|image|mimes:jpg,jpeg,png,webp,svg|max:2048',
                'remove_image'=> 'sometimes|boolean',
                'status'      => 'sometimes|integer|in:0,1',
            ]);

            if ($v->fails()) {
                return $this->fail($v->errors()->first(), self::VALIDATION_ERR);
            }

            $data = [];

            if ($request->filled('company_id')) {
                $data['company_id'] = (int) $request->input('company_id');
            }
            if ($request->has('parent_id')) {
                $pid = $request->input('parent_id');
                $data['parent_id'] = $pid === '' || $pid === null ? null : (int) $pid;
            }
            if ($request->filled('name')) {
                $data['name'] = trim($request->input('name'));
            }
            if ($request->has('description')) {
                $data['description'] = $request->input('description');
            }
            if ($request->has('status')) {
                $data['status'] = (int) $request->input('status');
            }

            // Prevent self-parent
            if (array_key_exists('parent_id', $data) && (int) $data['parent_id'] === $id) {
                return $this->fail('A category cannot be its own parent.', self::VALIDATION_ERR);
            }

            // If parent_id is changing, ensure it belongs to the same company
            $effectiveCompanyId = $data['company_id'] ?? $category->company_id;
            $effectiveParentId  = array_key_exists('parent_id', $data) ? $data['parent_id'] : $category->parent_id;
            $effectiveName      = $data['name'] ?? $category->name;

            if ($effectiveParentId) {
                $parent = Category::find($effectiveParentId);
                if (!$parent || (int) $parent->company_id !== (int) $effectiveCompanyId) {
                    return $this->fail(
                        'Parent category must belong to the same company.',
                        self::VALIDATION_ERR
                    );
                }
            }

            // Duplicate check
            $dup = Category::where('company_id', $effectiveCompanyId)
                ->where('name', $effectiveName)
                ->where('id', '!=', $id)
                ->when($effectiveParentId,
                    fn ($q) => $q->where('parent_id', $effectiveParentId),
                    fn ($q) => $q->whereNull('parent_id'))
                ->exists();

            if ($dup) {
                return $this->fail(
                    'A category with this name already exists here.',
                    self::DUPLICATE
                );
            }

            // Image handling
            if ($request->hasFile('image')) {
                if ($category->image) {
                    Storage::disk('public')->delete($category->image);
                }
                $data['image'] = $request->file('image')->store('categories', 'public');
            } elseif ($request->boolean('remove_image')) {
                if ($category->image) {
                    Storage::disk('public')->delete($category->image);
                }
                $data['image'] = null;
            }

            if (empty($data)) {
                return $this->fail('No fields to update.', self::VALIDATION_ERR);
            }

            $category->update($data);

            return $this->ok(
                'Category updated successfully.',
                $category->fresh()->load(['company', 'parent'])->toApiArray()
            );
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // ═════════════════════════════════════════════════════
    // 6. DELETE (cascades to descendants)
    // DELETE /api/Category/delete
    // Body or query: { id }
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

            $category = $this->findOwnedCategory($id, $authUser);
            if (!$category) {
                return $this->fail(
                    'Category not found or not under your administration.',
                    self::NOT_FOUND
                );
            }

            // Collect this category + descendants, delete images along the way
            $ids = $this->collectTreeIds($id);
            $rows = Category::whereIn('id', $ids)->get();

            foreach ($rows as $row) {
                if ($row->image) {
                    Storage::disk('public')->delete($row->image);
                }
            }

            // Delete children first, then the target (avoids FK issues if
            // nullOnDelete isn't set)
            $rows->where('id', '!=', $id)->each->delete();
            $category->delete();

            return $this->ok('Category deleted successfully.', [
                'deleted' => count($ids),
            ]);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // ─── Helpers ─────────────────────────────────────────

    /**
     * Return the given category id plus all descendant ids.
     */
    private function collectTreeIds(int $rootId): array
    {
        $ids     = [$rootId];
        $queue   = [$rootId];
        $visited = [$rootId => true];
        $safety  = 0;

        while (!empty($queue) && $safety++ < 500) {
            $children = Category::whereIn('parent_id', $queue)->pluck('id')->toArray();
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
}