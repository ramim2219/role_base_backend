<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class CompanyController extends Controller
{
    private const OK             = 1200;
    private const NOT_FOUND      = 2001;
    private const FORBIDDEN      = 3001;
    private const DUPLICATE      = 3002;
    private const VALIDATION_ERR = 4000;
    private const SERVER_ERR     = 5000;

    // Logo constraints
    private const LOGO_MAX_KB = 2048;  // 2 MB
    private const LOGO_MIMES  = 'jpg,jpeg,png,webp,svg';
    private const LOGO_MIMETYPES = 'image/jpeg,image/png,image/webp,image/svg+xml';

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

    // ─── Slug helper ─────────────────────────────────────
    private function makeSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'company';
        $slug = $base;
        $i    = 1;

        while (
            Company::where('slug', $slug)
                ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $slug = $base . '-' . $i++;
        }

        return $slug;
    }

    // ─── Logo helpers ────────────────────────────────────
    /**
     * Store an uploaded logo and return its public-disk path (e.g. "logos/xxx.webp").
     */
    private function storeLogo(\Illuminate\Http\UploadedFile $file): string
    {
        $folder = 'logos';
        $name   = 'logo_' . uniqid() . '_' . time();

        // SVG: store as-is (do not resize/re-encode)
        if ($file->getClientMimeType() === 'image/svg+xml') {
            return $file->storeAs($folder, $name . '.svg', 'public');
        }

        // Raster: store with the original extension
        $ext  = strtolower($file->getClientOriginalExtension() ?: 'png');
        $path = $file->storeAs($folder, $name . '.' . $ext, 'public');

        return $path;
    }

    /**
     * Delete a previously stored logo (no-op if it doesn't exist).
     */
    private function deleteLogoIfAny(?string $path): void
    {
        if ($path && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }

    /**
     * Validation rule set for the logo field.
     */
    private function logoRules(bool $required = false): array
    {
        return [
            $required ? 'required' : 'nullable',
            'file',
            'mimes:' . self::LOGO_MIMES,
            'mimetypes:' . self::LOGO_MIMETYPES,
            'max:' . self::LOGO_MAX_KB,
        ];
    }

    // ═════════════════════════════════════════════════════
    // 1. LIST
    // GET /api/Company/get_all
    //   ?only_mine=1 → only rows created_by = current user
    // ═════════════════════════════════════════════════════
    public function getAll(Request $request)
    {
        try {
            $authUser = $request->user();
            $onlyMine = (int) $request->query('only_mine', 0) === 1;

            $query = Company::with('companyType')->orderBy('name');

            if ($onlyMine) {
                $query->where('created_by', $authUser->id);
            }

            $companies = $query->get()->map(fn ($c) => $c->toApiArray());

            return $this->ok('Companies fetched successfully.', $companies);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // ═════════════════════════════════════════════════════
    // 2. GET BY ID
    // GET /api/Company/get_by_id?id=X
    // ═════════════════════════════════════════════════════
    public function getById(Request $request)
    {
        try {
            $id = (int) $request->query('id', 0);
            if (!$id) {
                return $this->fail('Id is required.', self::VALIDATION_ERR);
            }

            $company = Company::with('companyType')->find($id);
            if (!$company) {
                return $this->fail('Company not found.', self::NOT_FOUND);
            }

            return $this->ok('Company fetched successfully.', $company->toApiArray());
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // ═════════════════════════════════════════════════════
    // 3. CREATE
    // POST /api/Company/save
    // multipart/form-data:
    //   name, company_type_id, address, contact,
    //   email?, logo? (file), status?, slug?
    // ═════════════════════════════════════════════════════
    public function save(Request $request)
    {
        try {
            $v = Validator::make($request->all(), [
                'name'            => 'required|string|max:200',
                'company_type_id' => 'required|integer|exists:company_types,id',
                'address'         => 'required|string',
                'contact'         => 'required|string|max:20',
                'email'           => 'nullable|email|max:150',
                'logo'            => $this->logoRules(false),
                'status'          => 'nullable|integer|in:0,1',
                'slug'            => 'nullable|string|max:200',
            ]);

            if ($v->fails()) {
                return $this->fail($v->errors()->first(), self::VALIDATION_ERR);
            }

            $name = trim($request->input('name'));

            if ($request->filled('slug')) {
                $slug = Str::slug($request->input('slug'));
                if (Company::where('slug', $slug)->exists()) {
                    return $this->fail('Slug is already in use.', self::DUPLICATE);
                }
            } else {
                $slug = $this->makeSlug($name);
            }

            $logoPath = null;
            if ($request->hasFile('logo')) {
                $logoPath = $this->storeLogo($request->file('logo'));
            }

            $company = Company::create([
                'name'            => $name,
                'logo'            => $logoPath,
                'company_type_id' => (int) $request->input('company_type_id'),
                'slug'            => $slug,
                'status'          => (int) $request->input('status', 1),
                'address'         => trim((string) $request->input('address')),
                'contact'         => trim((string) $request->input('contact')),
                'email'           => $request->input('email') ?: null,
                'created_by'      => auth()->id(),
            ]);

            return $this->ok('Company created successfully.', [
                'id'   => $company->id,
                'slug' => $company->slug,
            ]);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // ═════════════════════════════════════════════════════
    // 4. UPDATE
    // PUT/POST /api/Company/update  (use _method=PUT for multipart)
    // multipart/form-data:
    //   id, ...optional fields
    //   logo? (file) — replaces existing
    //   remove_logo? (0|1) — clears existing logo
    // ═════════════════════════════════════════════════════
    public function update(Request $request)
    {
        try {
            $v = Validator::make($request->all(), [
                'id'              => 'required|integer|min:1',
                'name'            => 'sometimes|string|max:200',
                'company_type_id' => 'sometimes|integer|exists:company_types,id',
                'address'         => 'sometimes|string',
                'contact'         => 'sometimes|string|max:20',
                'email'           => 'sometimes|nullable|email|max:150',
                'logo'            => $this->logoRules(false),
                'remove_logo'     => 'sometimes|boolean',
                'status'          => 'sometimes|integer|in:0,1',
                'slug'            => 'sometimes|string|max:200',
            ]);

            if ($v->fails()) {
                return $this->fail($v->errors()->first(), self::VALIDATION_ERR);
            }

            $id = (int) $request->input('id');
            $company = Company::find($id);
            if (!$company) {
                return $this->fail('Company not found.', self::NOT_FOUND);
            }

            $data = [];

            if ($request->filled('name')) {
                $data['name'] = trim($request->input('name'));
            }

            if ($request->filled('slug')) {
                $slug = Str::slug($request->input('slug'));
                if (
                    Company::where('slug', $slug)
                        ->where('id', '!=', $id)
                        ->exists()
                ) {
                    return $this->fail('Slug is already in use.', self::DUPLICATE);
                }
                $data['slug'] = $slug;
            } elseif ($request->filled('name')) {
                $data['slug'] = $this->makeSlug($request->input('name'), $id);
            }

            if ($request->filled('company_type_id')) {
                $data['company_type_id'] = (int) $request->input('company_type_id');
            }
            if ($request->has('address')) {
                $data['address'] = trim((string) $request->input('address'));
            }
            if ($request->has('contact')) {
                $data['contact'] = trim((string) $request->input('contact'));
            }
            if ($request->has('email')) {
                $data['email'] = $request->input('email') ?: null;
            }
            if ($request->has('status')) {
                $data['status'] = (int) $request->input('status');
            }

            // ─── Logo handling ───────────────────────────
            if ($request->hasFile('logo')) {
                // Replace: delete old file, store new one
                $this->deleteLogoIfAny($company->logo);
                $data['logo'] = $this->storeLogo($request->file('logo'));
            } elseif ($request->boolean('remove_logo')) {
                // Explicit clear
                $this->deleteLogoIfAny($company->logo);
                $data['logo'] = null;
            }
            // else: no file sent and not clearing → leave logo untouched

            if (empty($data)) {
                return $this->fail('No fields to update.', self::VALIDATION_ERR);
            }

            $company->update($data);

            return $this->ok(
                'Company updated successfully.',
                $company->fresh()->load('companyType')->toApiArray()
            );
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // ═════════════════════════════════════════════════════
    // 5. DELETE
    // DELETE /api/Company/delete
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

            $company = Company::find($id);
            if (!$company) {
                return $this->fail('Company not found.', self::NOT_FOUND);
            }

            // Guard: refuse delete if any users are assigned.
            $inUse = User::where('company_id', $id)->exists();
            if ($inUse) {
                return $this->fail(
                    'Cannot delete — users are still assigned to this company.',
                    self::FORBIDDEN
                );
            }

            // Delete the logo file from disk (avoid orphans)
            $this->deleteLogoIfAny($company->logo);

            $company->delete();

            return $this->ok('Company deleted successfully.');
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }
    // ═════════════════════════════════════════════════════
    // GET COMPANIES BY CREATOR
    // GET /api/Company/get_company_by_createdby?created_by=X
    //
    // Returns every company whose created_by = X.
    //
    // Rules:
    //   • Super admin can query any creator id.
    //   • Non-super-admins can only query their own id.
    //   • If created_by is omitted, defaults to the caller's own id.
    // ═════════════════════════════════════════════════════
    public function getByCreatedBy(Request $request)
    {
        try {
            $authUser = $request->user();
            $isSuperAdmin = $authUser->hasRole('super_admin');

            $createdBy = (int) (
                $request->query('created_by')
                ?: $authUser->id
            );

            if (!$createdBy) {
                return $this->fail('created_by is required.', self::VALIDATION_ERR);
            }

            // Non-super-admins can only see their own companies
            if (!$isSuperAdmin && $createdBy !== (int) $authUser->id) {
                return $this->fail(
                    'You can only view companies you created.',
                    self::FORBIDDEN
                );
            }

            $companies = Company::with('companyType')
                ->where('created_by', $createdBy)
                ->orderBy('name')
                ->get()
                ->map(fn ($c) => $c->toApiArray());

            return $this->ok('Companies fetched successfully.', $companies);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }
}