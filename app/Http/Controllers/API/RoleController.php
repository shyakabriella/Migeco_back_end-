<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController as BaseController;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class RoleController extends BaseController
{
    /**
     * Check if current user can manage roles.
     */
    private function canManageRoles($user): bool
    {
        return $user && (
            $user->isAdmin()
            || $user->role?->slug === 'admin'
            || (
                method_exists($user, 'hasPermission')
                && $user->hasPermission('manage_roles')
            )
        );
    }

    /**
     * Check if current user can view roles.
     */
    private function canViewRoles($user): bool
    {
        return $user && (
            $this->canManageRoles($user)
            || (
                method_exists($user, 'hasPermission')
                && $user->hasPermission('view_roles')
            )
        );
    }

    /**
     * Display roles.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.',
            ], 401);
        }

        if (!$this->canViewRoles($user)) {
            return $this->sendError('Permission Denied.', [
                'error' => 'You are not allowed to view roles.',
            ], 403);
        }

        $roles = Role::query()
            ->when($request->filled('search'), function ($query) use ($request) {
                $query->where(function ($searchQuery) use ($request) {
                    $searchQuery->where('name', 'LIKE', '%' . $request->search . '%')
                        ->orWhere('slug', 'LIKE', '%' . $request->search . '%')
                        ->orWhere('description', 'LIKE', '%' . $request->search . '%');
                });
            })
            ->orderBy('name')
            ->get();

        return $this->sendResponse($roles, 'Roles retrieved successfully.');
    }

    /**
     * Store new role.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.',
            ], 401);
        }

        if (!$this->canManageRoles($user)) {
            return $this->sendError('Permission Denied.', [
                'error' => 'Only Admin can create roles.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => [
                'required',
                'string',
                'max:255',
                'unique:roles,name',
            ],
            'slug' => [
                'nullable',
                'string',
                'max:255',
                'unique:roles,slug',
            ],
            'description' => [
                'nullable',
                'string',
            ],
            'permissions' => [
                'nullable',
                'array',
            ],
            'permissions.*' => [
                'string',
                'max:255',
            ],
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors(), 422);
        }

        $role = Role::create([
            'name' => $request->name,
            'slug' => $request->slug ?: Str::slug($request->name, '_'),
            'description' => $request->description,
            'permissions' => $request->permissions ?? [],
        ]);

        return $this->sendResponse($role, 'Role created successfully.');
    }

    /**
     * Show one role.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.',
            ], 401);
        }

        if (!$this->canViewRoles($user)) {
            return $this->sendError('Permission Denied.', [
                'error' => 'You are not allowed to view role details.',
            ], 403);
        }

        $role = Role::find($id);

        if (!$role) {
            return $this->sendError('Not Found.', [
                'error' => 'Role not found.',
            ], 404);
        }

        return $this->sendResponse($role, 'Role retrieved successfully.');
    }

    /**
     * Update role.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.',
            ], 401);
        }

        if (!$this->canManageRoles($user)) {
            return $this->sendError('Permission Denied.', [
                'error' => 'Only Admin can update roles.',
            ], 403);
        }

        $role = Role::find($id);

        if (!$role) {
            return $this->sendError('Not Found.', [
                'error' => 'Role not found.',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('roles', 'name')->ignore($role->id),
            ],
            'slug' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('roles', 'slug')->ignore($role->id),
            ],
            'description' => [
                'nullable',
                'string',
            ],
            'permissions' => [
                'nullable',
                'array',
            ],
            'permissions.*' => [
                'string',
                'max:255',
            ],
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors(), 422);
        }

        $payload = [];

        if ($request->filled('name')) {
            $payload['name'] = $request->name;
        }

        if ($request->filled('slug')) {
            $payload['slug'] = $request->slug;
        }

        if ($request->has('description')) {
            $payload['description'] = $request->description;
        }

        if ($request->has('permissions')) {
            $payload['permissions'] = $request->permissions ?? [];
        }

        $role->update($payload);

        return $this->sendResponse($role, 'Role updated successfully.');
    }

    /**
     * Delete role.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.',
            ], 401);
        }

        if (!$this->canManageRoles($user)) {
            return $this->sendError('Permission Denied.', [
                'error' => 'Only Admin can delete roles.',
            ], 403);
        }

        $role = Role::find($id);

        if (!$role) {
            return $this->sendError('Not Found.', [
                'error' => 'Role not found.',
            ], 404);
        }

        if (in_array($role->slug, ['admin', 'geologist', 'viewer'])) {
            return $this->sendError('Delete Failed.', [
                'error' => 'System roles cannot be deleted.',
            ], 400);
        }

        if (User::where('role_id', $role->id)->exists()) {
            return $this->sendError('Delete Failed.', [
                'error' => 'This role is assigned to users. Move users to another role first.',
            ], 400);
        }

        $role->delete();

        return $this->sendResponse([], 'Role deleted successfully.');
    }
}