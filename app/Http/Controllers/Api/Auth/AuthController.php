<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * POST /api/auth/login
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if ($user->status !== 1) {
            throw ValidationException::withMessages([
                'email' => ['Your account is inactive. Contact administrator.'],
            ]);
        }

        // Revoke old tokens (optional — single-device login)
        $user->tokens()->delete();

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'data' => [
                'user'  => $this->formatUser($user),
                'token' => $token,
            ],
        ]);
    }

    /**
     * POST /api/auth/logout
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully',
        ]);
    }

    /**
     * GET /api/auth/me
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => [
                'user' => $this->formatUser($request->user()),
            ],
        ]);
    }

    /**
     * GET /api/auth/me/menus
     * The core of the permission system.
     */
    public function myMenus(Request $request): JsonResponse
    {
        $user = $request->user();
        $menus = collect();

        // Super admin: sees ALL menus
        if ($user->hasRole('super_admin')) {
            $menus = \App\Models\Menu::whereNull('parent_id')
                ->with('children')
                ->orderBy('name')
                ->get();
        } else {
            // Get menu IDs assigned to this user OR their user_type
            $menuIds = \App\Models\MenuAssignedData::where(function ($q) use ($user) {
                $q->where('user_id', $user->id);
                if ($user->user_type_id) {
                    $q->orWhere('user_type_id', $user->user_type_id);
                }
            })->pluck('menu_id')->unique();

            $menus = \App\Models\Menu::whereIn('id', $menuIds)
                ->whereNull('parent_id')
                ->with('children')
                ->orderBy('name')
                ->get();
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'menus' => $menus,
            ],
        ]);
    }

    /**
     * Format user payload for API.
     */
    private function formatUser(User $user): array
    {
        return [
            'id'            => $user->id,
            'name'          => $user->name,
            'email'         => $user->email,
            'username'      => $user->username,
            'company_id'    => $user->company_id,
            'user_type_id'  => $user->user_type_id,
            'status'        => $user->status,
            'roles'         => $user->getRoleNames(),
            'permissions'   => $user->getAllPermissions()->pluck('name'),
        ];
    }
}