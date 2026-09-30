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

    // ─── Ownership helpers ───────────────────────────────
    /**
     * A caller can manage a detail if:
     *   • they are a super admin, OR
     *   • the detail belongs to themselves, OR
     *   • they created the underlying user
     */
    private function canManage($authUser, UserDetail $detail): bool
    {
        if (!$authUser) return false;
        if ($authUser->hasRole('super_admin')) return true;
        if ((int) $authUser->id === (int) $detail->user_id) return true;

        return User::where('id', $detail->user_id)
            ->where('created_by', $authUser->id)
            ->exists();
    }

    // ─── Serializer ──────────────────────────────────────
    private function toApiArray(UserDetail $detail): array
    {
        // asset() prepends APP_URL from .env, giving an absolute URL
        $imageUrl = $detail->image ? asset('storage/' . $detail->image) : null;

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

            'user' => $detail->relationLoaded('user') && $detail->user ? [
                'id'    => $detail->user->id,
                'name'  => $detail->user->name,
                'email' => $detail->user->email,
            ] : null,
        ];
    }

    // ═════════════════════════════════════════════════════
    // 1. GET BY USER ID
    // ═════════════════════════════════════════════════════
    public function getByUserId(Request $request)
    {
        try {
            $userId = (int) $request->query('user_id');

            if (!$userId) {
                return $this->fail('user_id is required.', self::VALIDATION_ERR);
            }

            $detail = UserDetail::with('user')->where('user_id', $userId)->first();

            if (!$detail) {
                return $this->fail('User details not found.', self::NOT_FOUND);
            }

            return $this->ok('User details fetched successfully.', $this->toApiArray($detail));
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // ═════════════════════════════════════════════════════
    // 2. GET BY USER ID (creator-scoped)
    // ═════════════════════════════════════════════════════
    public function getByUserIdCreator(Request $request)
    {
        try {
            $authUser = $request->user();
            $userId   = (int) $request->query('user_id');

            if (!$userId) {
                return $this->fail('user_id is required.', self::VALIDATION_ERR);
            }

            // Allow self, or a user the caller created, or super admin
            if (!$authUser->hasRole('super_admin')) {
                $allowed = (int) $authUser->id === $userId
                    || User::where('id', $userId)
                        ->where('created_by', $authUser->id)
                        ->exists();

                if (!$allowed) {
                    return $this->fail(
                        'You can only view your own details or those of users you created.',
                        self::FORBIDDEN
                    );
                }
            }

            $detail = UserDetail::with('user')->where('user_id', $userId)->first();

            if (!$detail) {
                return $this->fail('User details not found.', self::NOT_FOUND);
            }

            return $this->ok('User details fetched successfully.', $this->toApiArray($detail));
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // ═════════════════════════════════════════════════════
    // 3. GET ALL
    // ═════════════════════════════════════════════════════
    public function getAll(Request $request)
    {
        try {
            $authUser = $request->user();

            $query = UserDetail::with('user')->orderBy('full_name');

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
    // 4. UPDATE
    // PUT /api/UserDetail/update_userDetails
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

            if (!$this->canManage($authUser, $detail)) {
                return $this->fail(
                    'You can only update your own details or those of users you created.',
                    self::FORBIDDEN
                );
            }

            $v = Validator::make($request->all(), [
                'full_name'                  => 'sometimes|string|max:150',
                'contact'                    => 'sometimes|nullable|string|max:20',
                'present_address'            => 'sometimes|nullable|string',
                'permanent_address'          => 'sometimes|nullable|string',
                'father_name'                => 'sometimes|nullable|string|max:150',
                'mother_name'                => 'sometimes|nullable|string|max:150',
                'date_of_birth'              => 'sometimes|nullable|date',
                'marital_status'             => 'sometimes|nullable|string|max:30',
                'spouse_name'                => 'nullable|string|max:150',
                'nid_number'                 => 'nullable|string|max:30',
                'gender'                     => 'sometimes|nullable|string|max:20',
                'birth_number'               => 'nullable|string|max:30',
                'religion'                   => 'nullable|string|max:50',
                'nationality'                => 'sometimes|nullable|string|max:50',
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

            if ($request->hasFile('image')) {
                if ($detail->image && Storage::disk('public')->exists($detail->image)) {
                    Storage::disk('public')->delete($detail->image);
                }
                $data['image'] = $request->file('image')->store('user_details', 'public');
            }

            unset($data['user_id'], $data['created_by']);

            $detail->update($data);

            return $this->ok(
                'User details updated successfully.',
                $this->toApiArray($detail->fresh('user'))
            );
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // ═════════════════════════════════════════════════════
    // 5. DELETE
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

            if (!$this->canManage($authUser, $detail)) {
                return $this->fail(
                    'You can only delete your own details or those of users you created.',
                    self::FORBIDDEN
                );
            }

            if ($detail->image && Storage::disk('public')->exists($detail->image)) {
                Storage::disk('public')->delete($detail->image);
            }

            $detail->delete();

            return $this->ok('User details deleted successfully.', ['id' => $id]);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // ═════════════════════════════════════════════════════
    // 6. CREATE OR UPSERT (self-service)
    // POST /api/UserDetail/save_userDetails
    //
    // Rules:
    //   • On first create → full_name is required.
    //   • On update       → partial save allowed.
    //   • user_id is always the caller's own id (never trusted from input).
    // ═════════════════════════════════════════════════════
    public function save(Request $request)
    {
        try {
            $authUser = $request->user();
            if (!$authUser) {
                return $this->fail('Unauthenticated.', self::FORBIDDEN);
            }

            $detail = UserDetail::where('user_id', $authUser->id)->first();
            $isNew  = false;

            if (!$detail) {
                $detail = new UserDetail();
                $detail->user_id    = $authUser->id;
                $detail->created_by = $authUser->id;
                $isNew = true;
            }

            // Only require full_name when a new record is being created AND
            // the request is actually trying to save Step 1 (i.e. full_name present).
            // The frontend prevents saving any step before Step 1 for a new profile,
            // so this is a safety net.
            $fullNameRule = $isNew ? 'required|string|max:150' : 'sometimes|string|max:150';

            $v = Validator::make($request->all(), [
                'full_name'                  => $fullNameRule,

                'contact'                    => 'nullable|string|max:20',
                'present_address'            => 'nullable|string',
                'permanent_address'          => 'nullable|string',
                'father_name'                => 'nullable|string|max:150',
                'mother_name'                => 'nullable|string|max:150',
                'date_of_birth'              => 'nullable|date',
                'marital_status'             => 'nullable|string|max:30',
                'gender'                     => 'nullable|string|max:20',
                'nationality'                => 'nullable|string|max:50',
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

            unset($data['user_id'], $data['created_by']);

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