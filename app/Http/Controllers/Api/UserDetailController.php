<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserDetail;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;

class UserDetailController extends Controller
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

    // ─── Serializer ──────────────────────────────────────
    private function toApiArray(UserDetail $detail): array
    {
        $imageUrl = null;
        if ($detail->image) {
            // Storage::url() → "/storage/..." (relative). Frontend prepends base URL.
            $imageUrl = Storage::url($detail->image);
        }

        return [
            'id'                         => $detail->id,
            'user_id'                    => $detail->user_id,
            'image'                      => $detail->image,
            'image_url'                  => $imageUrl,
            'full_name'                  => $detail->full_name,
            'contact'                    => $detail->contact,
            'present_address'            => $detail->present_address,
            'permanent_address'          => $detail->permanent_address,
            'father_name'                => $detail->father_name,
            'mother_name'                => $detail->mother_name,
            'date_of_birth'              => optional($detail->date_of_birth)->format('Y-m-d'),
            'marital_status'             => $detail->marital_status,
            'spouse_name'                => $detail->spouse_name,
            'nid_number'                 => $detail->nid_number,
            'gender'                     => $detail->gender,
            'birth_number'               => $detail->birth_number,
            'religion'                   => $detail->religion,
            'nationality'                => $detail->nationality,
            'blood_group'                => $detail->blood_group,
            'joining_date'               => optional($detail->joining_date)->format('Y-m-d'),
            'resignation_date'           => optional($detail->resignation_date)->format('Y-m-d'),
            'emergency_contact_name'     => $detail->emergency_contact_name,
            'emergency_contact_relation' => $detail->emergency_contact_relation,
            'emergency_contact_phone'    => $detail->emergency_contact_phone,
            'emergency_contact_address'  => $detail->emergency_contact_address,
            'created_by'                 => $detail->created_by,

            // Light user info (safe fields only)
            'user' => $detail->relationLoaded('user') && $detail->user ? [
                'id'    => $detail->user->id,
                'name'  => $detail->user->name,
                'email' => $detail->user->email,
            ] : null,
        ];
    }

    // ═════════════════════════════════════════════════════
    // 1. GET USER DETAILS BY USER ID
    // GET /api/UserDetail/get_userDetails_by_userid?user_id=X
    // ═════════════════════════════════════════════════════
    public function getByUserId(Request $request)
    {
        try {
            $userId = (int) $request->query('user_id');

            if (!$userId) {
                return $this->fail('user_id is required.', self::VALIDATION_ERR);
            }

            $detail = UserDetail::with('user')
                ->where('user_id', $userId)
                ->first();

            if (!$detail) {
                return $this->fail('User details not found.', self::NOT_FOUND);
            }

            return $this->ok('User details fetched successfully.', $this->toApiArray($detail));
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // ═════════════════════════════════════════════════════
    // 2. GET USER DETAILS BY USER ID (creator-scoped)
    // GET /api/UserDetail/get_userDetails_by_userid_creator?user_id=X
    //
    // Returns the user detail only if the caller created that user.
    // Super admins bypass the check.
    // ═════════════════════════════════════════════════════
    public function getByUserIdCreator(Request $request)
    {
        try {
            $authUser = $request->user();
            $userId   = (int) $request->query('user_id');

            if (!$userId) {
                return $this->fail('user_id is required.', self::VALIDATION_ERR);
            }

            // Ownership check — unless super admin
            if (!$authUser->hasRole('super_admin')) {
                $owns = User::where('id', $userId)
                    ->where('created_by', $authUser->id)
                    ->exists();

                if (!$owns) {
                    return $this->fail(
                        'You can only view details for users you created.',
                        self::FORBIDDEN
                    );
                }
            }

            $detail = UserDetail::with('user')
                ->where('user_id', $userId)
                ->first();

            if (!$detail) {
                return $this->fail('User details not found.', self::NOT_FOUND);
            }

            return $this->ok('User details fetched successfully.', $this->toApiArray($detail));
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // ═════════════════════════════════════════════════════
    // 3. GET ALL USER DETAILS
    // GET /api/UserDetail/get_all_userDetails
    //   ?created_by=X        (optional filter)
    //   ?only_mine=1         (optional — filter by auth user's created users)
    //   ?search=name         (optional — full_name / contact / nid)
    // ═════════════════════════════════════════════════════
    public function getAll(Request $request)
    {
        try {
            $authUser = $request->user();

            $query = UserDetail::with('user')
                ->orderBy('full_name');

            // Filter: only_mine → details of users created by the caller
            if ($request->boolean('only_mine')) {
                $createdUserIds = User::where('created_by', $authUser->id)
                    ->pluck('id')
                    ->toArray();

                $query->whereIn('user_id', $createdUserIds);
            } elseif ($request->filled('created_by')) {
                $creatorId = (int) $request->input('created_by');
                $createdUserIds = User::where('created_by', $creatorId)
                    ->pluck('id')
                    ->toArray();

                $query->whereIn('user_id', $createdUserIds);
            }

            // Optional search
            if ($request->filled('search')) {
                $term = '%' . $request->input('search') . '%';
                $query->where(function ($q) use ($term) {
                    $q->where('full_name', 'like', $term)
                      ->orWhere('contact', 'like', $term)
                      ->orWhere('nid_number', 'like', $term);
                });
            }

            $rows = $query->get()->map(fn ($d) => $this->toApiArray($d));

            return $this->ok('User details fetched successfully.', $rows);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // ═════════════════════════════════════════════════════
    // 4. UPDATE USER DETAILS
    // PUT /api/UserDetail/update_userDetails
    //
    // Body (all optional — only sent fields update):
    //   id                    (required) — user_details.id
    //   image                 (file, optional)
    //   full_name, contact, present_address, permanent_address,
    //   father_name, mother_name, date_of_birth, marital_status,
    //   spouse_name, nid_number, gender, birth_number, religion,
    //   nationality, blood_group, joining_date, resignation_date,
    //   emergency_contact_* 
    // ═════════════════════════════════════════════════════
    public function update(Request $request)
    {
        try {
            $authUser = $request->user();
            $id       = (int) $request->input('id');

            if (!$id) {
                return $this->fail('id is required.', self::VALIDATION_ERR);
            }

            $detail = UserDetail::find($id);
            if (!$detail) {
                return $this->fail('User details not found.', self::NOT_FOUND);
            }

            // Ownership check — unless super admin
            if (!$authUser->hasRole('super_admin')) {
                $owns = User::where('id', $detail->user_id)
                    ->where('created_by', $authUser->id)
                    ->exists();

                if (!$owns) {
                    return $this->fail(
                        'You can only update details for users you created.',
                        self::FORBIDDEN
                    );
                }
            }

            // ─── Validate ────────────────────────────────
            $v = Validator::make($request->all(), [
                'full_name'                  => 'sometimes|string|max:150',
                'contact'                    => 'sometimes|string|max:20',
                'present_address'            => 'sometimes|string',
                'permanent_address'          => 'sometimes|string',
                'father_name'                => 'sometimes|string|max:150',
                'mother_name'                => 'sometimes|string|max:150',
                'date_of_birth'              => 'sometimes|date',
                'marital_status'             => 'sometimes|string|max:30',
                'spouse_name'                => 'nullable|string|max:150',
                'nid_number'                 => 'nullable|string|max:30',
                'gender'                     => 'sometimes|string|max:20',
                'birth_number'               => 'nullable|string|max:30',
                'religion'                   => 'nullable|string|max:50',
                'nationality'                => 'sometimes|string|max:50',
                'blood_group'                => 'nullable|string|max:5',
                'joining_date'               => 'nullable|date',
                'resignation_date'           => 'nullable|date',
                'emergency_contact_name'     => 'nullable|string|max:150',
                'emergency_contact_relation' => 'nullable|string|max:50',
                'emergency_contact_phone'    => 'nullable|string|max:20',
                'emergency_contact_address'  => 'nullable|string',
                'image'                      => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            ]);

            if ($v->fails()) {
                return $this->fail($v->errors()->first(), self::VALIDATION_ERR);
            }

            $data = $v->validated();

            // ─── Handle image upload ─────────────────────
            if ($request->hasFile('image')) {
                // Delete old image
                if ($detail->image && Storage::disk('public')->exists($detail->image)) {
                    Storage::disk('public')->delete($detail->image);
                }

                $path = $request->file('image')->store('user_details', 'public');
                $data['image'] = $path;
            }

            // Never allow overriding user_id or created_by through this endpoint
            unset($data['user_id'], $data['created_by']);

            $detail->update($data);

            return $this->ok('User details updated successfully.', $this->toApiArray($detail->fresh('user')));
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // ═════════════════════════════════════════════════════
    // 5. DELETE USER DETAILS
    // DELETE /api/UserDetail/delete_userDetails
    // Body: { id }  OR query: ?id=X
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

            $detail = UserDetail::find($id);
            if (!$detail) {
                return $this->fail('User details not found.', self::NOT_FOUND);
            }

            // Ownership check — unless super admin
            if (!$authUser->hasRole('super_admin')) {
                $owns = User::where('id', $detail->user_id)
                    ->where('created_by', $authUser->id)
                    ->exists();

                if (!$owns) {
                    return $this->fail(
                        'You can only delete details for users you created.',
                        self::FORBIDDEN
                    );
                }
            }

            // Delete image from disk first
            if ($detail->image && Storage::disk('public')->exists($detail->image)) {
                Storage::disk('public')->delete($detail->image);
            }

            $detail->delete();

            return $this->ok('User details deleted successfully.', [
                'id' => $id,
            ]);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }
        // ═════════════════════════════════════════════════════
    // 6. CREATE OR UPSERT USER DETAILS (self-service)
    // POST /api/UserDetail/save_userDetails
    //
    // Creates a UserDetail row for the authenticated user
    // if one doesn't exist; otherwise updates it.
    // The user_id is always the caller's own id — never trusted from input.
    // ═════════════════════════════════════════════════════
    public function save(Request $request)
    {
        try {
            $authUser = $request->user();
            if (!$authUser) {
                return $this->fail('Unauthenticated.', self::FORBIDDEN);
            }

            // Find or start a new row for the caller
            $detail = UserDetail::where('user_id', $authUser->id)->first();
            $isNew  = false;

            if (!$detail) {
                $detail = new UserDetail();
                $detail->user_id   = $authUser->id;
                $detail->created_by = $authUser->id;
                $isNew = true;
            }

            // ─── Validation ──────────────────────────────
            // On first create, a few fields are required.
            // On update, everything is optional (partial update).
            $requiredRule = $isNew ? 'required' : 'sometimes';

            $v = Validator::make($request->all(), [
                'full_name'                  => "{$requiredRule}|string|max:150",
                'contact'                    => "{$requiredRule}|string|max:20",
                'present_address'            => "{$requiredRule}|string",
                'permanent_address'          => "{$requiredRule}|string",
                'father_name'                => "{$requiredRule}|string|max:150",
                'mother_name'                => "{$requiredRule}|string|max:150",
                'date_of_birth'              => "{$requiredRule}|date",
                'marital_status'             => "{$requiredRule}|string|max:30",
                'gender'                     => "{$requiredRule}|string|max:20",
                'nationality'                => "{$requiredRule}|string|max:50",
                'spouse_name'                => 'nullable|string|max:150',
                'nid_number'                 => 'nullable|string|max:30',
                'birth_number'               => 'nullable|string|max:30',
                'religion'                   => 'nullable|string|max:50',
                'blood_group'                => 'nullable|string|max:5',
                'joining_date'               => 'nullable|date',
                'resignation_date'           => 'nullable|date',
                'emergency_contact_name'     => 'nullable|string|max:150',
                'emergency_contact_relation' => 'nullable|string|max:50',
                'emergency_contact_phone'    => 'nullable|string|max:20',
                'emergency_contact_address'  => 'nullable|string',
                'image'                      => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            ]);

            if ($v->fails()) {
                return $this->fail($v->errors()->first(), self::VALIDATION_ERR);
            }

            $data = $v->validated();

            // Never let the client set these
            unset($data['user_id'], $data['created_by']);

            // Handle image upload
            if ($request->hasFile('image')) {
                if ($detail->image && Storage::disk('public')->exists($detail->image)) {
                    Storage::disk('public')->delete($detail->image);
                }
                $data['image'] = $request->file('image')->store('user_details', 'public');
            }

            $detail->fill($data);
            $detail->save();

            return $this->ok(
                $isNew ? 'Profile created successfully.' : 'Profile updated successfully.',
                $this->toApiArray($detail->fresh('user'))
            );
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }
}