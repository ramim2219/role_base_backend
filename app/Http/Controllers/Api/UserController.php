<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
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

    private function findOwnedUser(int $id, $authUser): ?User
    {
        return User::where('id', $id)
            ->where('created_by', $authUser->id)
            ->first();
    }

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

            return $this->ok('User fetched successfully.', $this->formatUser($user));
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function getMyUsers(Request $request)
    {
        try {
            $authUser = $request->user();

            $users = User::where('created_by', $authUser->id)
                ->orderBy('id', 'desc')
                ->get()
                ->map(fn ($u) => $this->formatUser($u));

            return $this->ok('Users fetched successfully.', $users);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

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

            return $this->ok('User updated successfully.', $this->formatUser($user->fresh()));
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

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

    private function formatUser(User $user): array
    {
        return [
            'id'           => $user->id,
            'name'         => $user->name,
            'email'        => $user->email,
            'username'     => $user->username,
            'company_id'   => $user->company_id,
            'user_type_id' => $user->user_type_id,
            'created_by'   => $user->created_by,
            'status'       => $user->status,
            'created_at'   => $user->created_at,
            'updated_at'   => $user->updated_at,
        ];
    }
}
