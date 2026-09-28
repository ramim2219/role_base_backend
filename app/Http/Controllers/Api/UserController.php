<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class UserController extends Controller
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

    // ─── Ownership helper ────────────────────────────────
    private function findOwnedUser(int $id, $authUser): ?User
    {
        return User::where('id', $id)
            ->where('created_by', $authUser->id)
            ->first();
    }

    // ═════════════════════════════════════════════════════
    // 1. GET USER BY ID
    // GET /api/User/get_user_by_id?user_id=X
    // ═════════════════════════════════════════════════════
    public function getUserById(Request $request)
    {
        try {
            $id = (int) $request->query('user_id', 0);
            if (!$id) {
                return $this->fail('User id is required.', self::VALIDATION_ERR);
            }

            $authUser = $request->user();
            $user = $this->findOwnedUser($id, $authUser);

            if (!$user) {
                return $this->fail(
                    'User not found or not under your administration.',
                    self::NOT_FOUND
                );
            }

            $user->load(['userType', 'company']);

            return $this->ok('User fetched successfully.', $this->formatUser($user));
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // ═════════════════════════════════════════════════════
    // 2. GET MY USERS
    // GET /api/User/get_my_users
    // ═════════════════════════════════════════════════════
    public function getMyUsers(Request $request)
    {
        try {
            $authUser = $request->user();

            $users = User::with(['userType', 'company'])
                ->where('created_by', $authUser->id)
                ->orderBy('id', 'desc')
                ->get()
                ->map(fn ($u) => $this->formatUser($u));

            return $this->ok('Users fetched successfully.', $users);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // ═════════════════════════════════════════════════════
    // 3. CREATE USER
    // POST /api/User/save_user
    //
    // Body:
    //   name           (required, max 150)
    //   email          (required, unique, valid email)
    //   username       (required, unique, max 100)
    //   password       (required, min 6)
    //   user_type_id   (required, must exist)
    //   company_id     (nullable — if omitted, derived from the user type)
    //   status         (nullable, 0|1 — default 1)
    //
    // Rules:
    //   • Super admin can create under ANY user type.
    //   • Admin can create ONLY under user types THEY created.
    //   • created_by = current user
    //   • company_id = request value, or the user type's company, or null
    // ═════════════════════════════════════════════════════
    public function saveUser(Request $request)
    {
        try {
            $v = Validator::make($request->all(), [
                'name'         => 'required|string|max:150',
                'email'        => 'required|email|max:150|unique:users,email',
                'username'     => 'required|string|max:100|unique:users,username',
                'password'     => 'required|string|min:6',
                'user_type_id' => 'required|integer|exists:user_types,id',
                'company_id'   => 'sometimes|nullable|integer|exists:companies,id',
                'status'       => 'sometimes|integer|in:0,1',
            ]);

            if ($v->fails()) {
                return $this->fail($v->errors()->first(), self::VALIDATION_ERR);
            }

            $authUser     = $request->user();
            $isSuperAdmin = $authUser->hasRole('super_admin');

            $userType = UserType::find((int) $request->input('user_type_id'));
            if (!$userType) {
                return $this->fail('User type not found.', self::NOT_FOUND);
            }

            // Non-super-admins can only use types they created
            if (!$isSuperAdmin && (int) $userType->created_by !== (int) $authUser->id) {
                return $this->fail(
                    'You can only create users under user types you created.',
                    self::FORBIDDEN
                );
            }

            // Company resolution:
            //   1. explicit company_id if sent
            //   2. else the user type's company_id
            //   3. else null (global)
            $companyId = $request->has('company_id')
                ? ($request->input('company_id') ?: null)
                : $userType->company_id;

            $user = User::create([
                'name'         => trim($request->input('name')),
                'email'        => trim($request->input('email')),
                'username'     => trim($request->input('username')),
                'password'     => Hash::make($request->input('password')),
                'company_id'   => $companyId,
                'user_type_id' => $userType->id,
                'created_by'   => $authUser->id,
                'status'       => (int) $request->input('status', 1),
            ]);

            $user->load(['userType', 'company']);

            return $this->ok('User created successfully.', $this->formatUser($user));
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // ═════════════════════════════════════════════════════
    // 4. UPDATE USER
    // PUT /api/User/update_user
    // ═════════════════════════════════════════════════════
    public function updateUser(Request $request)
    {
        try {
            $v = Validator::make($request->all(), [
                'id'           => 'required|integer|min:1',
                'name'         => 'sometimes|string|max:150',
                'email'        => 'sometimes|email|max:150',
                'username'     => 'sometimes|string|max:100',
                'password'     => 'sometimes|string|min:6',
                'company_id'   => 'sometimes|nullable|integer|min:1',
                'user_type_id' => 'sometimes|nullable|integer|min:1',
                'status'       => 'sometimes|integer|in:0,1',
            ]);

            if ($v->fails()) {
                return $this->fail($v->errors()->first(), self::VALIDATION_ERR);
            }

            $authUser = $request->user();
            $id = (int) $request->input('id');

            $user = $this->findOwnedUser($id, $authUser);
            if (!$user) {
                return $this->fail(
                    'User not found or not under your administration.',
                    self::NOT_FOUND
                );
            }

            if ($request->filled('email') && $request->email !== $user->email) {
                if (User::where('email', $request->email)->where('id', '!=', $id)->exists()) {
                    return $this->fail('Email is already in use.', self::DUPLICATE);
                }
            }

            if ($request->filled('username') && $request->username !== $user->username) {
                if (User::where('username', $request->username)->where('id', '!=', $id)->exists()) {
                    return $this->fail('Username is already in use.', self::DUPLICATE);
                }
            }

            $data = [];
            if ($request->filled('name'))         $data['name']         = trim($request->name);
            if ($request->filled('email'))        $data['email']        = trim($request->email);
            if ($request->filled('username'))     $data['username']     = trim($request->username);
            if ($request->filled('password'))     $data['password']     = Hash::make($request->password);
            if ($request->has('company_id'))      $data['company_id']   = $request->company_id ?: null;
            if ($request->has('user_type_id'))    $data['user_type_id'] = $request->user_type_id ?: null;
            if ($request->has('status'))          $data['status']       = (int) $request->status;

            if (empty($data)) {
                return $this->fail('No fields to update.', self::VALIDATION_ERR);
            }

            $user->update($data);

            $user->load(['userType', 'company']);

            return $this->ok('User updated successfully.', $this->formatUser($user->fresh(['userType', 'company'])));
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // ═════════════════════════════════════════════════════
    // 5. DELETE USER
    // DELETE /api/User/delete_user
    // ═════════════════════════════════════════════════════
    public function deleteUser(Request $request)
    {
        try {
            $id = (int) (
                $request->input('id')
                ?? $request->input('user_id')
                ?? $request->query('user_id')
                ?? 0
            );

            if (!$id) {
                return $this->fail('User id is required.', self::VALIDATION_ERR);
            }

            $authUser = $request->user();

            if ($id === $authUser->id) {
                return $this->fail('You cannot delete your own account.', self::FORBIDDEN);
            }

            $user = $this->findOwnedUser($id, $authUser);
            if (!$user) {
                return $this->fail(
                    'User not found or not under your administration.',
                    self::NOT_FOUND
                );
            }

            $user->tokens()->delete();
            $user->delete();

            return $this->ok('User deleted successfully.');
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // ═════════════════════════════════════════════════════
    // 6. GET USERS BY MY USER TYPES
    // GET /api/User/get_users_by_my_types
    // ═════════════════════════════════════════════════════
    public function getUsersByMyUserTypes(Request $request)
    {
        try {
            $authUser = $request->user();

            $typeIds = UserType::where('created_by', $authUser->id)
                ->pluck('id')
                ->toArray();

            if (empty($typeIds)) {
                return $this->ok('No user types created by you yet.', []);
            }

            $filterTypeId = $request->query('user_type_id');
            if ($filterTypeId !== null && $filterTypeId !== '') {
                $filterTypeId = (int) $filterTypeId;

                if (!in_array($filterTypeId, $typeIds, true)) {
                    return $this->fail(
                        'That user type is not under your administration.',
                        self::FORBIDDEN
                    );
                }

                $typeIds = [$filterTypeId];
            }

            $users = User::with(['userType', 'company'])
                ->whereIn('user_type_id', $typeIds)
                ->orderBy('id', 'desc')
                ->get()
                ->map(fn ($u) => $this->formatUser($u));

            return $this->ok('Users fetched successfully.', $users);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // ═════════════════════════════════════════════════════
    // 7. GET USERS GROUPED BY MY USER TYPES
    // GET /api/User/get_grouped_by_my_types
    // ═════════════════════════════════════════════════════
    public function getGroupedByMyUserTypes(Request $request)
    {
        try {
            $authUser = $request->user();

            $types = UserType::with('company')
                ->where('created_by', $authUser->id)
                ->orderBy('name')
                ->get();

            if ($types->isEmpty()) {
                return $this->ok('No user types created by you yet.', []);
            }

            $typeIds = $types->pluck('id')->toArray();

            $users = User::with(['userType', 'company'])
                ->whereIn('user_type_id', $typeIds)
                ->orderBy('name')
                ->get();

            $usersByType = $users->groupBy('user_type_id');

            $result = $types->map(function ($type) use ($usersByType) {
                $list = $usersByType->get($type->id, collect());

                return [
                    'user_type' => [
                        'id'         => $type->id,
                        'name'       => $type->name,
                        'company_id' => $type->company_id,
                        'company'    => $type->company
                            ? [
                                'id'   => $type->company->id,
                                'name' => $type->company->name,
                            ]
                            : null,
                    ],
                    'user_count' => $list->count(),
                    'users'      => $list->map(fn ($u) => $this->formatUser($u))->values(),
                ];
            })->values();

            return $this->ok('Grouped users fetched successfully.', $result);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // ─── API shape ───────────────────────────────────────
    private function formatUser(User $user): array
    {
        return [
            'id'           => $user->id,
            'name'         => $user->name,
            'email'        => $user->email,
            'username'     => $user->username,
            'company_id'   => $user->company_id,
            'company'      => $user->relationLoaded('company') && $user->company
                ? [
                    'id'   => $user->company->id,
                    'name' => $user->company->name,
                ]
                : null,
            'user_type_id' => $user->user_type_id,
            'user_type'    => $user->relationLoaded('userType') && $user->userType
                ? [
                    'id'   => $user->userType->id,
                    'name' => $user->userType->name,
                ]
                : null,
            'created_by'   => $user->created_by,
            'status'       => $user->status,
            'created_at'   => $user->created_at,
            'updated_at'   => $user->updated_at,
        ];
    }
}