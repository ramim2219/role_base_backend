<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class BrandController extends Controller
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
    private function findOwnedBrand(int $id, $authUser): ?Brand
    {
        return Brand::where('id', $id)
            ->where('created_by', $authUser->id)
            ->first();
    }

    // ═════════════════════════════════════════════════════
    // 1. GET ALL (my brands)
    // GET /api/Brand/get_all
    // ═════════════════════════════════════════════════════
    public function getAll(Request $request)
    {
        try {
            $authUser = $request->user();

            $query = Brand::with(['category', 'company'])
                ->where('created_by', $authUser->id)
                ->orderBy('name');

            if ($request->filled('company_id')) {
                $query->where('company_id', (int) $request->input('company_id'));
            }
            if ($request->filled('category_id')) {
                $query->where('category_id', (int) $request->input('category_id'));
            }
            if ($request->filled('status')) {
                $query->where('status', (int) $request->input('status'));
            }
            if ($request->filled('search')) {
                $term = '%' . $request->input('search') . '%';
                $query->where('name', 'like', $term);
            }

            $rows = $query->get()->map(fn ($b) => $b->toApiArray());

            return $this->ok('Brands fetched successfully.', $rows);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // ═════════════════════════════════════════════════════
    // 2. GET BY CATEGORY
    // GET /api/Brand/get_by_category?category_id=X
    // ═════════════════════════════════════════════════════
    public function getByCategory(Request $request)
    {
        try {
            $authUser   = $request->user();
            $categoryId = (int) $request->query('category_id');

            if (!$categoryId) {
                return $this->fail('category_id is required.', self::VALIDATION_ERR);
            }

            $rows = Brand::with(['category', 'company'])
                ->where('category_id', $categoryId)
                ->where('created_by', $authUser->id)
                ->orderBy('name')
                ->get()
                ->map(fn ($b) => $b->toApiArray());

            return $this->ok('Brands fetched successfully.', $rows);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // ═════════════════════════════════════════════════════
    // 3. GET BY COMPANY
    // GET /api/Brand/get_by_company?company_id=X
    // ═════════════════════════════════════════════════════
    public function getByCompany(Request $request)
    {
        try {
            $authUser  = $request->user();
            $companyId = (int) $request->query('company_id');

            if (!$companyId) {
                return $this->fail('company_id is required.', self::VALIDATION_ERR);
            }

            $rows = Brand::with(['category', 'company'])
                ->where('company_id', $companyId)
                ->where('created_by', $authUser->id)
                ->orderBy('name')
                ->get()
                ->map(fn ($b) => $b->toApiArray());

            return $this->ok('Brands fetched successfully.', $rows);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // ═════════════════════════════════════════════════════
    // 4. GET BY ID
    // GET /api/Brand/get_by_id?id=X
    // ═════════════════════════════════════════════════════
    public function getById(Request $request)
    {
        try {
            $id = (int) $request->query('id', 0);
            if (!$id) {
                return $this->fail('id is required.', self::VALIDATION_ERR);
            }

            $authUser = $request->user();
            $brand = $this->findOwnedBrand($id, $authUser);

            if (!$brand) {
                return $this->fail(
                    'Brand not found or not under your administration.',
                    self::NOT_FOUND
                );
            }

            $brand->load(['category', 'company']);

            return $this->ok('Brand fetched successfully.', $brand->toApiArray());
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // ═════════════════════════════════════════════════════
    // 5. CREATE
    // POST /api/Brand/save (multipart/form-data)
    // ═════════════════════════════════════════════════════
    public function save(Request $request)
    {
        try {
            $authUser = $request->user();

            $v = Validator::make($request->all(), [
                'name'        => 'required|string|max:150',
                'category_id' => 'required|integer|exists:categories,id',
                'company_id'  => 'required|integer|exists:companies,id',
                'logo'        => 'nullable|image|mimes:jpg,jpeg,png,webp,svg|max:2048',
                'status'      => 'nullable|integer|in:0,1',
            ]);

            if ($v->fails()) {
                return $this->fail($v->errors()->first(), self::VALIDATION_ERR);
            }

            $name       = trim($request->input('name'));
            $categoryId = (int) $request->input('category_id');
            $companyId  = (int) $request->input('company_id');

            $category = Category::find($categoryId);
            if (!$category) {
                return $this->fail('Category not found.', self::NOT_FOUND);
            }
            if ((int) $category->company_id !== $companyId) {
                return $this->fail(
                    'Selected category does not belong to the given company.',
                    self::VALIDATION_ERR
                );
            }

            // Duplicate name within the same company + category
            $dup = Brand::where('company_id', $companyId)
                ->where('category_id', $categoryId)
                ->where('name', $name)
                ->exists();

            if ($dup) {
                return $this->fail(
                    'A brand with this name already exists in this category.',
                    self::DUPLICATE
                );
            }

            $logoPath = null;
            if ($request->hasFile('logo')) {
                $logoPath = $request->file('logo')->store('brands', 'public');
            }

            $brand = Brand::create([
                'name'        => $name,
                'category_id' => $categoryId,
                'company_id'  => $companyId,
                'logo'        => $logoPath,
                'status'      => (int) $request->input('status', 1),
                'created_by'  => $authUser->id,
            ]);

            return $this->ok('Brand created successfully.', ['id' => $brand->id]);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // ═════════════════════════════════════════════════════
    // 6. UPDATE
    // POST /api/Brand/update  (use _method=PUT for files)
    // ═════════════════════════════════════════════════════
    public function update(Request $request)
    {
        try {
            $authUser = $request->user();
            $id       = (int) $request->input('id');

            if (!$id) {
                return $this->fail('id is required.', self::VALIDATION_ERR);
            }

            $brand = $this->findOwnedBrand($id, $authUser);
            if (!$brand) {
                return $this->fail(
                    'Brand not found or not under your administration.',
                    self::NOT_FOUND
                );
            }

            $v = Validator::make($request->all(), [
                'name'        => 'sometimes|string|max:150',
                'category_id' => 'sometimes|integer|exists:categories,id',
                'company_id'  => 'sometimes|integer|exists:companies,id',
                'logo'        => 'sometimes|nullable|image|mimes:jpg,jpeg,png,webp,svg|max:2048',
                'remove_logo' => 'sometimes|boolean',
                'status'      => 'sometimes|integer|in:0,1',
            ]);

            if ($v->fails()) {
                return $this->fail($v->errors()->first(), self::VALIDATION_ERR);
            }

            $data = [];

            if ($request->filled('name')) {
                $data['name'] = trim($request->input('name'));
            }
            if ($request->filled('company_id')) {
                $data['company_id'] = (int) $request->input('company_id');
            }
            if ($request->filled('category_id')) {
                $data['category_id'] = (int) $request->input('category_id');
            }
            if ($request->has('status')) {
                $data['status'] = (int) $request->input('status');
            }

            // Effective values after this update
            $effCompanyId  = $data['company_id']  ?? $brand->company_id;
            $effCategoryId = $data['category_id'] ?? $brand->category_id;
            $effName       = $data['name']        ?? $brand->name;

            $category = Category::find($effCategoryId);
            if (!$category || (int) $category->company_id !== (int) $effCompanyId) {
                return $this->fail(
                    'Selected category does not belong to the given company.',
                    self::VALIDATION_ERR
                );
            }

            // Duplicate check (excluding self)
            $dup = Brand::where('company_id', $effCompanyId)
                ->where('category_id', $effCategoryId)
                ->where('name', $effName)
                ->where('id', '!=', $id)
                ->exists();

            if ($dup) {
                return $this->fail(
                    'A brand with this name already exists in this category.',
                    self::DUPLICATE
                );
            }

            // Logo handling
            if ($request->hasFile('logo')) {
                if ($brand->logo) {
                    Storage::disk('public')->delete($brand->logo);
                }
                $data['logo'] = $request->file('logo')->store('brands', 'public');
            } elseif ($request->boolean('remove_logo')) {
                if ($brand->logo) {
                    Storage::disk('public')->delete($brand->logo);
                }
                $data['logo'] = null;
            }

            if (empty($data)) {
                return $this->fail('No fields to update.', self::VALIDATION_ERR);
            }

            $brand->update($data);

            return $this->ok(
                'Brand updated successfully.',
                $brand->fresh()->load(['category', 'company'])->toApiArray()
            );
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // ═════════════════════════════════════════════════════
    // 7. DELETE
    // DELETE /api/Brand/delete
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

            $brand = $this->findOwnedBrand($id, $authUser);
            if (!$brand) {
                return $this->fail(
                    'Brand not found or not under your administration.',
                    self::NOT_FOUND
                );
            }

            if ($brand->logo) {
                Storage::disk('public')->delete($brand->logo);
            }

            $brand->delete();

            return $this->ok('Brand deleted successfully.', ['id' => $id]);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }
}