<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController as BaseController;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class RegisterController extends BaseController
{
    /**
     * Register / Create User API
     *
     * Important:
     * This method is NOT for public registration.
     * Only authenticated Admin can create other users.
     */
    public function register(Request $request): JsonResponse
    {
        $authUser = $request->user();

        /*
        |--------------------------------------------------------------------------
        | Check if user is logged in
        |--------------------------------------------------------------------------
        */
        if (!$authUser) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.'
            ], 401);
        }

        /*
        |--------------------------------------------------------------------------
        | Only Admin can create users
        |--------------------------------------------------------------------------
        */
        if (!$authUser->isAdmin()) {
            return $this->sendError('Permission Denied.', [
                'error' => 'Only Admin can create users.'
            ], 403);
        }

        /*
        |--------------------------------------------------------------------------
        | Validate User Input
        |--------------------------------------------------------------------------
        */
        $validator = Validator::make($request->all(), [
            'role_id' => [
                'required',
                'integer',
                'exists:roles,id',
            ],
            'name' => [
                'required',
                'string',
                'max:255',
            ],
            'email' => [
                'required',
                'email',
                'max:255',
                'unique:users,email',
            ],
            'password' => [
                'required',
                'string',
                'min:8',
            ],
            'c_password' => [
                'required',
                'same:password',
            ],
            'phone' => [
                'nullable',
                'string',
                'max:30',
            ],
            'department' => [
                'nullable',
                'string',
                'max:255',
            ],
            'status' => [
                'nullable',
                Rule::in(['active', 'inactive', 'suspended']),
            ],
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors(), 422);
        }

        /*
        |--------------------------------------------------------------------------
        | Create User
        |--------------------------------------------------------------------------
        | Password will be hashed automatically because User model has:
        | 'password' => 'hashed'
        */
        $user = User::create([
            'role_id' => $request->role_id,
            'name' => $request->name,
            'email' => $request->email,
            'password' => $request->password,
            'phone' => $request->phone,
            'department' => $request->department,
            'status' => $request->status ?? 'active',
            'created_by' => $authUser->id,
        ]);

        $user->load('role');

        $success = [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'department' => $user->department,
                'status' => $user->status,
                'role' => [
                    'id' => $user->role?->id,
                    'name' => $user->role?->name,
                    'slug' => $user->role?->slug,
                ],
                'created_by' => $authUser->name,
            ],
        ];

        return $this->sendResponse($success, 'User created successfully by Admin.');
    }

    /**
     * Login API
     */
    public function login(Request $request): JsonResponse
    {
        /*
        |--------------------------------------------------------------------------
        | Validate Login Input
        |--------------------------------------------------------------------------
        */
        $validator = Validator::make($request->all(), [
            'email' => [
                'required',
                'email',
            ],
            'password' => [
                'required',
                'string',
            ],
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors(), 422);
        }

        /*
        |--------------------------------------------------------------------------
        | Attempt Login
        |--------------------------------------------------------------------------
        */
        if (!Auth::attempt([
            'email' => $request->email,
            'password' => $request->password,
        ])) {
            return $this->sendError('Unauthorised.', [
                'error' => 'Invalid email or password.'
            ], 401);
        }

        /** @var User $user */
        $user = Auth::user();

        /*
        |--------------------------------------------------------------------------
        | Check User Status
        |--------------------------------------------------------------------------
        */
        if (!$user->isActive()) {
            return $this->sendError('Account Disabled.', [
                'error' => 'Your account is not active. Please contact Admin.'
            ], 403);
        }

        $user->load('role');

        /*
        |--------------------------------------------------------------------------
        | Create Sanctum Token
        |--------------------------------------------------------------------------
        */
        $token = $user->createToken('DMS-API-TOKEN')->plainTextToken;

        $success = [
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'department' => $user->department,
                'status' => $user->status,
                'role' => [
                    'id' => $user->role?->id,
                    'name' => $user->role?->name,
                    'slug' => $user->role?->slug,
                    'permissions' => $user->role?->permissions ?? [],
                ],
            ],
        ];

        return $this->sendResponse($success, 'User login successfully.');
    }

    /**
     * Logout API
     */
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You are not logged in.'
            ], 401);
        }

        $user->currentAccessToken()->delete();

        return $this->sendResponse([], 'User logout successfully.');
    }

    /**
     * Logged-in User Profile API
     */
    public function profile(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You are not logged in.'
            ], 401);
        }

        $user->load('role');

        $success = [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'department' => $user->department,
                'status' => $user->status,
                'role' => [
                    'id' => $user->role?->id,
                    'name' => $user->role?->name,
                    'slug' => $user->role?->slug,
                    'permissions' => $user->role?->permissions ?? [],
                ],
            ],
        ];

        return $this->sendResponse($success, 'User profile retrieved successfully.');
    }
}