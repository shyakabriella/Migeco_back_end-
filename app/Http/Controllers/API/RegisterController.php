<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController as BaseController;
use App\Models\User;
use App\Notifications\UserCreatedResetPasswordNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

class RegisterController extends BaseController
{
    /**
     * Check if current user can manage users.
     */
    private function canManageUsers($user): bool
    {
        return $user && (
            $user->isAdmin()
            || $user->role?->slug === 'admin'
            || (
                method_exists($user, 'hasPermission')
                && $user->hasPermission('manage_users')
            )
        );
    }

    /**
     * Format user response consistently for frontend.
     */
    private function formatUser(User $user): array
    {
        $user->loadMissing('role');

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'department' => $user->department,
            'status' => $user->status,
            'role_id' => $user->role_id,
            'role' => [
                'id' => $user->role?->id,
                'name' => $user->role?->name,
                'slug' => $user->role?->slug,
                'permissions' => $user->role?->permissions ?? [],
            ],
            'created_at' => $user->created_at,
            'updated_at' => $user->updated_at,
        ];
    }

    /**
     * Register / Create User API.
     *
     * Important:
     * This method is NOT for public registration.
     * Only authenticated Admin can create other users.
     */
    public function register(Request $request): JsonResponse
    {
        $authUser = $request->user();

        if (!$authUser) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.',
            ], 401);
        }

        if (!$this->canManageUsers($authUser)) {
            return $this->sendError('Permission Denied.', [
                'error' => 'Only Admin can create users.',
            ], 403);
        }

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
        | Admin does not provide a password. A temporary password is generated
        | and sent to the user by email. This is intended for local/testing.
        */
        $temporaryPassword = $this->generateTemporaryPassword();

        $user = User::create([
            'role_id' => $request->role_id,
            'name' => $request->name,
            'email' => $request->email,
            'password' => $temporaryPassword,
            'phone' => $request->phone,
            'department' => $request->department,
            'status' => $request->status ?? 'active',
            'created_by' => $authUser->id,
        ]);

        $temporaryPasswordEmailSent = $this->sendTemporaryPasswordNotification(
            $user,
            $authUser,
            $temporaryPassword
        );

        return $this->sendResponse(
            [
                'user' => $this->formatUser($user),
                'temporary_password_email_sent' => $temporaryPasswordEmailSent,
            ],
            $temporaryPasswordEmailSent
                ? 'User created successfully. Temporary password email sent.'
                : 'User created successfully, but temporary password email was not sent.'
        );
    }

    /**
     * Generate a readable temporary password for local user onboarding.
     */
    private function generateTemporaryPassword(): string
    {
        return 'Migeco-' . Str::random(8) . '1!';
    }

    /**
     * Send temporary password email to newly created user.
     */
    private function sendTemporaryPasswordNotification(
        User $user,
        User $authUser,
        string $temporaryPassword
    ): bool
    {
        try {
            $user->notify(
                new UserCreatedResetPasswordNotification(
                    $temporaryPassword,
                    $authUser
                )
            );

            return true;
        } catch (Throwable $exception) {
            Log::warning('Failed to send user temporary password notification.', [
                'user_id' => $user->id,
                'email' => $user->email,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Login API.
     */
    public function login(Request $request): JsonResponse
    {
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

        if (!Auth::attempt([
            'email' => $request->email,
            'password' => $request->password,
        ])) {
            return $this->sendError('Unauthorised.', [
                'error' => 'Invalid email or password.',
            ], 401);
        }

        /** @var User $user */
        $user = Auth::user();

        if (!$user->isActive()) {
            return $this->sendError('Account Disabled.', [
                'error' => 'Your account is not active. Please contact Admin.',
            ], 403);
        }

        $token = $user->createToken('DMS-API-TOKEN')->plainTextToken;

        $success = [
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => $this->formatUser($user),
        ];

        return $this->sendResponse($success, 'User login successfully.');
    }

    /**
     * Logout API.
     */
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You are not logged in.',
            ], 401);
        }

        $user->currentAccessToken()?->delete();

        return $this->sendResponse([], 'User logout successfully.');
    }

    /**
     * Logged-in user profile API.
     */
    public function profile(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You are not logged in.',
            ], 401);
        }

        return $this->sendResponse(
            ['user' => $this->formatUser($user)],
            'User profile retrieved successfully.'
        );
    }

    /**
     * Frontend compatibility endpoint.
     *
     * GET /api/me
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You are not logged in.',
            ], 401);
        }

        return $this->sendResponse(
            $this->formatUser($user),
            'Current user retrieved successfully.'
        );
    }

    /**
     * List users.
     */
    public function users(Request $request): JsonResponse
    {
        $authUser = $request->user();

        if (!$authUser) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.',
            ], 401);
        }

        if (!$this->canManageUsers($authUser)) {
            return $this->sendError('Permission Denied.', [
                'error' => 'Only Admin can view users.',
            ], 403);
        }

        $users = User::with('role')
            ->when($request->filled('status'), function ($query) use ($request) {
                $query->where('status', $request->status);
            })
            ->when($request->filled('role_id'), function ($query) use ($request) {
                $query->where('role_id', $request->role_id);
            })
            ->when($request->filled('search'), function ($query) use ($request) {
                $query->where(function ($searchQuery) use ($request) {
                    $searchQuery->where('name', 'LIKE', '%' . $request->search . '%')
                        ->orWhere('email', 'LIKE', '%' . $request->search . '%')
                        ->orWhere('department', 'LIKE', '%' . $request->search . '%');
                });
            })
            ->orderBy('name')
            ->get()
            ->map(function (User $user) {
                return $this->formatUser($user);
            })
            ->values();

        return $this->sendResponse($users, 'Users retrieved successfully.');
    }

    /**
     * Show one user.
     */
    public function showUser(Request $request, string $id): JsonResponse
    {
        $authUser = $request->user();

        if (!$authUser) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.',
            ], 401);
        }

        if (!$this->canManageUsers($authUser)) {
            return $this->sendError('Permission Denied.', [
                'error' => 'Only Admin can view user details.',
            ], 403);
        }

        $user = User::with('role')->find($id);

        if (!$user) {
            return $this->sendError('Not Found.', [
                'error' => 'User not found.',
            ], 404);
        }

        return $this->sendResponse(
            $this->formatUser($user),
            'User retrieved successfully.'
        );
    }

    /**
     * Update user.
     */
    public function updateUser(Request $request, string $id): JsonResponse
    {
        $authUser = $request->user();

        if (!$authUser) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.',
            ], 401);
        }

        if (!$this->canManageUsers($authUser)) {
            return $this->sendError('Permission Denied.', [
                'error' => 'Only Admin can update users.',
            ], 403);
        }

        $user = User::find($id);

        if (!$user) {
            return $this->sendError('Not Found.', [
                'error' => 'User not found.',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'role_id' => [
                'nullable',
                'integer',
                'exists:roles,id',
            ],
            'name' => [
                'nullable',
                'string',
                'max:255',
            ],
            'email' => [
                'nullable',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
            'password' => [
                'nullable',
                'string',
                'min:8',
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

        $payload = $request->only([
            'role_id',
            'name',
            'email',
            'phone',
            'department',
            'status',
        ]);

        if ($request->filled('password')) {
            $payload['password'] = $request->password;
        }

        $user->update($payload);

        return $this->sendResponse(
            $this->formatUser($user),
            'User updated successfully.'
        );
    }

    /**
     * Delete user.
     */
    public function deleteUser(Request $request, string $id): JsonResponse
    {
        $authUser = $request->user();

        if (!$authUser) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.',
            ], 401);
        }

        if (!$this->canManageUsers($authUser)) {
            return $this->sendError('Permission Denied.', [
                'error' => 'Only Admin can delete users.',
            ], 403);
        }

        if ((string) $authUser->id === (string) $id) {
            return $this->sendError('Delete Failed.', [
                'error' => 'You cannot delete your own account.',
            ], 400);
        }

        $user = User::find($id);

        if (!$user) {
            return $this->sendError('Not Found.', [
                'error' => 'User not found.',
            ], 404);
        }

        $user->delete();

        return $this->sendResponse([], 'User deleted successfully.');
    }
}