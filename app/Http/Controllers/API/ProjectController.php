<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController as BaseController;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProjectController extends BaseController
{
    /**
     * Check if user can manage projects.
     *
     * Admin, Project Manager, and Document Controller can create/update/delete projects.
     */
    private function canManageProjects($user): bool
    {
        return $user && in_array($user->role?->slug, [
            'admin',
            'project_manager',
            'document_controller',
        ]);
    }

    /**
     * Display all projects.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.'
            ], 401);
        }

        $query = Project::with([
            'creator:id,name,email',
        ]);

        /*
        |--------------------------------------------------------------------------
        | Normal users should not see archived projects by default.
        |--------------------------------------------------------------------------
        */
        if (!$this->canManageProjects($user)) {
            $query->where('status', '!=', 'archived');
        }

        /*
        |--------------------------------------------------------------------------
        | Filters
        |--------------------------------------------------------------------------
        */
        $query->when($request->filled('status'), function ($q) use ($request) {
            $q->where('status', $request->status);
        });

        $query->when($request->filled('project_type'), function ($q) use ($request) {
            $q->where('project_type', $request->project_type);
        });

        $query->when($request->filled('security_level'), function ($q) use ($request) {
            $q->where('security_level', $request->security_level);
        });

        $query->when($request->filled('search'), function ($q) use ($request) {
            $q->where(function ($subQuery) use ($request) {
                $subQuery->where('name', 'LIKE', '%' . $request->search . '%')
                    ->orWhere('code', 'LIKE', '%' . $request->search . '%')
                    ->orWhere('description', 'LIKE', '%' . $request->search . '%')
                    ->orWhere('location_name', 'LIKE', '%' . $request->search . '%');
            });
        });

        $projects = $query
            ->orderBy('created_at', 'desc')
            ->get();

        return $this->sendResponse($projects, 'Projects retrieved successfully.');
    }

    /**
     * Store new project.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.'
            ], 401);
        }

        if (!$this->canManageProjects($user)) {
            return $this->sendError('Permission Denied.', [
                'error' => 'Only Admin, Project Manager, or Document Controller can create projects.'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => [
                'required',
                'string',
                'max:255',
            ],
            'code' => [
                'nullable',
                'string',
                'max:100',
                'unique:projects,code',
            ],
            'description' => [
                'nullable',
                'string',
            ],
            'location_name' => [
                'nullable',
                'string',
                'max:255',
            ],
            'latitude' => [
                'nullable',
                'numeric',
                'between:-90,90',
            ],
            'longitude' => [
                'nullable',
                'numeric',
                'between:-180,180',
            ],
            'project_type' => [
                'nullable',
                Rule::in([
                    'geological_survey',
                    'construction',
                    'technical_study',
                    'mining',
                    'administration',
                    'other',
                ]),
            ],
            'status' => [
                'nullable',
                Rule::in([
                    'planned',
                    'active',
                    'completed',
                    'archived',
                ]),
            ],
            'security_level' => [
                'nullable',
                Rule::in([
                    'public',
                    'internal',
                    'confidential',
                    'restricted',
                ]),
            ],
            'start_date' => [
                'nullable',
                'date',
            ],
            'end_date' => [
                'nullable',
                'date',
                'after_or_equal:start_date',
            ],
            'metadata' => [
                'nullable',
                'array',
            ],
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors(), 422);
        }

        /*
        |--------------------------------------------------------------------------
        | Auto-generate project code if user does not provide it.
        |--------------------------------------------------------------------------
        */
        $code = $request->code;

        if (!$code) {
            $prefix = match ($request->project_type ?? 'other') {
                'geological_survey' => 'GEO',
                'construction' => 'CONST',
                'technical_study' => 'TECH',
                'mining' => 'MIN',
                'administration' => 'ADMIN',
                default => 'PRJ',
            };

            $code = $prefix . '-' . now()->format('Y') . '-' . strtoupper(Str::random(6));
        }

        $project = Project::create([
            'created_by' => $user->id,
            'name' => $request->name,
            'code' => $code,
            'description' => $request->description,
            'location_name' => $request->location_name,
            'latitude' => $request->latitude,
            'longitude' => $request->longitude,
            'project_type' => $request->project_type ?? 'other',
            'status' => $request->status ?? 'planned',
            'security_level' => $request->security_level ?? 'internal',
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
            'metadata' => $request->metadata,
        ]);

        $project->load('creator:id,name,email');

        return $this->sendResponse($project, 'Project created successfully.');
    }

    /**
     * Show one project.
     */
    public function show(string $id): JsonResponse
    {
        $project = Project::with([
            'creator:id,name,email',
        ])->find($id);

        if (!$project) {
            return $this->sendError('Not Found.', [
                'error' => 'Project not found.'
            ], 404);
        }

        return $this->sendResponse($project, 'Project retrieved successfully.');
    }

    /**
     * Update project.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.'
            ], 401);
        }

        if (!$this->canManageProjects($user)) {
            return $this->sendError('Permission Denied.', [
                'error' => 'Only Admin, Project Manager, or Document Controller can update projects.'
            ], 403);
        }

        $project = Project::find($id);

        if (!$project) {
            return $this->sendError('Not Found.', [
                'error' => 'Project not found.'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:255',
            ],
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:100',
                Rule::unique('projects', 'code')->ignore($project->id),
            ],
            'description' => [
                'nullable',
                'string',
            ],
            'location_name' => [
                'nullable',
                'string',
                'max:255',
            ],
            'latitude' => [
                'nullable',
                'numeric',
                'between:-90,90',
            ],
            'longitude' => [
                'nullable',
                'numeric',
                'between:-180,180',
            ],
            'project_type' => [
                'nullable',
                Rule::in([
                    'geological_survey',
                    'construction',
                    'technical_study',
                    'mining',
                    'administration',
                    'other',
                ]),
            ],
            'status' => [
                'nullable',
                Rule::in([
                    'planned',
                    'active',
                    'completed',
                    'archived',
                ]),
            ],
            'security_level' => [
                'nullable',
                Rule::in([
                    'public',
                    'internal',
                    'confidential',
                    'restricted',
                ]),
            ],
            'start_date' => [
                'nullable',
                'date',
            ],
            'end_date' => [
                'nullable',
                'date',
                'after_or_equal:start_date',
            ],
            'metadata' => [
                'nullable',
                'array',
            ],
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors(), 422);
        }

        $project->update([
            'name' => $request->has('name') ? $request->name : $project->name,
            'code' => $request->has('code') ? $request->code : $project->code,
            'description' => $request->has('description') ? $request->description : $project->description,
            'location_name' => $request->has('location_name') ? $request->location_name : $project->location_name,
            'latitude' => $request->has('latitude') ? $request->latitude : $project->latitude,
            'longitude' => $request->has('longitude') ? $request->longitude : $project->longitude,
            'project_type' => $request->has('project_type') ? $request->project_type : $project->project_type,
            'status' => $request->has('status') ? $request->status : $project->status,
            'security_level' => $request->has('security_level') ? $request->security_level : $project->security_level,
            'start_date' => $request->has('start_date') ? $request->start_date : $project->start_date,
            'end_date' => $request->has('end_date') ? $request->end_date : $project->end_date,
            'metadata' => $request->has('metadata') ? $request->metadata : $project->metadata,
        ]);

        $project->load('creator:id,name,email');

        return $this->sendResponse($project, 'Project updated successfully.');
    }

    /**
     * Delete project.
     *
     * For safety, this uses soft delete.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.'
            ], 401);
        }

        if (!$this->canManageProjects($user)) {
            return $this->sendError('Permission Denied.', [
                'error' => 'Only Admin, Project Manager, or Document Controller can delete projects.'
            ], 403);
        }

        $project = Project::find($id);

        if (!$project) {
            return $this->sendError('Not Found.', [
                'error' => 'Project not found.'
            ], 404);
        }

        $project->delete();

        return $this->sendResponse([], 'Project deleted successfully.');
    }
}