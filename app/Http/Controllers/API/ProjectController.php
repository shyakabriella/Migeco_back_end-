<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController as BaseController;
use App\Models\Document;
use App\Models\GeologicalRecord;
use App\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

class ProjectController extends BaseController
{
    /*
    |--------------------------------------------------------------------------
    | Permissions
    |--------------------------------------------------------------------------
    | Admin sees and manages everything.
    | Geologist manages operational/geological projects.
    | Viewer can only read allowed project records.
    */

    private function canManageProjects($user): bool
    {
        return $user && (
            method_exists($user, 'isAdmin') && $user->isAdmin()
            || in_array($user->role?->slug, [
                'admin',
                'geologist',
                'project_manager',
                'document_controller',
            ], true)
            || (method_exists($user, 'hasPermission') && $user->hasPermission('manage_projects'))
        );
    }

    private function canViewRestrictedProjects($user): bool
    {
        return $user && (
            method_exists($user, 'isAdmin') && $user->isAdmin()
            || in_array($user->role?->slug, [
                'admin',
                'project_manager',
                'document_controller',
                'security_officer',
            ], true)
        );
    }

    private function isViewer($user): bool
    {
        return $user && $user->role?->slug === 'viewer';
    }

    private function validationRules(?Project $project = null): array
    {
        return [
            'name' => [
                $project ? 'sometimes' : 'required',
                'required',
                'string',
                'max:255',
            ],
            'code' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('projects', 'code')->ignore($project?->id),
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
            'project_manager_id' => [
                'nullable',
                'integer',
                'exists:users,id',
            ],
            'metadata' => [
                'nullable',
                'array',
            ],
        ];
    }

    private function applyVisibility(Builder $query, $user, Request $request): Builder
    {
        /*
        |--------------------------------------------------------------------------
        | Viewer role is read-only and should not see restricted projects.
        |--------------------------------------------------------------------------
        */
        if ($this->isViewer($user)) {
            $query->whereIn('security_level', ['public', 'internal'])
                ->where('status', '!=', 'archived');
        }

        /*
        |--------------------------------------------------------------------------
        | Non-managers do not see archived projects unless specifically allowed.
        |--------------------------------------------------------------------------
        */
        if (!$this->canManageProjects($user) && !$request->boolean('include_archived')) {
            $query->where('status', '!=', 'archived');
        }

        /*
        |--------------------------------------------------------------------------
        | Restricted projects are visible only to high-trust roles.
        |--------------------------------------------------------------------------
        */
        if (!$this->canViewRestrictedProjects($user)) {
            $query->where('security_level', '!=', 'restricted');
        }

        return $query;
    }

    private function applyFilters(Builder $query, Request $request): Builder
    {
        return $query
            ->when($request->filled('status'), function (Builder $q) use ($request) {
                $q->where('status', $request->status);
            })
            ->when($request->filled('project_type'), function (Builder $q) use ($request) {
                $q->where('project_type', $request->project_type);
            })
            ->when($request->filled('security_level'), function (Builder $q) use ($request) {
                $q->where('security_level', $request->security_level);
            })
            ->when($request->filled('created_by'), function (Builder $q) use ($request) {
                $q->where('created_by', $request->created_by);
            })
            ->when($request->filled('date_from'), function (Builder $q) use ($request) {
                $q->whereDate('created_at', '>=', $request->date_from);
            })
            ->when($request->filled('date_to'), function (Builder $q) use ($request) {
                $q->whereDate('created_at', '<=', $request->date_to);
            })
            ->when($request->filled('search'), function (Builder $q) use ($request) {
                $search = trim((string) $request->search);

                $q->where(function (Builder $subQuery) use ($search) {
                    $subQuery
                        ->where('name', 'LIKE', '%' . $search . '%')
                        ->orWhere('code', 'LIKE', '%' . $search . '%')
                        ->orWhere('description', 'LIKE', '%' . $search . '%')
                        ->orWhere('location_name', 'LIKE', '%' . $search . '%');
                });
            });
    }

    private function baseProjectQuery(): Builder
    {
        return Project::query()
            ->with([
                'creator:id,name,email',
                'manager:id,name,email',
            ])
            ->withCount([
                'documents as documents_count',
                'activeDocuments as active_documents_count',
                'archivedDocuments as archived_documents_count',
                'securityAlertDocuments as security_alerts_count',
            ]);
    }

    private function findProjectForUser(string $id, $user, Request $request): ?Project
    {
        $query = $this->baseProjectQuery();

        $this->applyVisibility($query, $user, $request);

        return $query->find($id);
    }

    private function generateProjectCode(?string $projectType): string
    {
        $prefix = match ($projectType ?? 'other') {
            'geological_survey' => 'GEO',
            'construction' => 'CONST',
            'technical_study' => 'TECH',
            'mining' => 'MIN',
            'administration' => 'ADMIN',
            default => 'PRJ',
        };

        do {
            $code = $prefix . '-' . now()->format('Y') . '-' . strtoupper(Str::random(6));
        } while (Project::where('code', $code)->exists());

        return $code;
    }

    private function documentQueryForProject(Project $project): Builder
    {
        return Document::query()
            ->where('project_id', $project->id);
    }

    private function categorizedDocumentQuery(Project $project, string $category): Builder
    {
        $query = $this->documentQueryForProject($project);

        return match ($category) {
            'study_area' => $query->where(function (Builder $q) {
                $q->whereIn('document_type', [
                        'survey_map',
                        'geological_report',
                        'technical_drawing',
                    ])
                    ->orWhere('title', 'LIKE', '%study area%')
                    ->orWhere('title', 'LIKE', '%map%')
                    ->orWhere('title', 'LIKE', '%location%')
                    ->orWhere('description', 'LIKE', '%study area%')
                    ->orWhere('description', 'LIKE', '%map%');
            }),
            'sample' => $query->where(function (Builder $q) {
                $q->where('title', 'LIKE', '%sample%')
                    ->orWhere('title', 'LIKE', '%rock%')
                    ->orWhere('title', 'LIKE', '%soil%')
                    ->orWhere('description', 'LIKE', '%sample%')
                    ->orWhere('description', 'LIKE', '%rock%')
                    ->orWhere('description', 'LIKE', '%soil%');
            }),
            'laboratory' => $query->where(function (Builder $q) {
                $q->where('title', 'LIKE', '%laboratory%')
                    ->orWhere('title', 'LIKE', '%lab%')
                    ->orWhere('title', 'LIKE', '%assay%')
                    ->orWhere('title', 'LIKE', '%test result%')
                    ->orWhere('description', 'LIKE', '%laboratory%')
                    ->orWhere('description', 'LIKE', '%assay%')
                    ->orWhere('description', 'LIKE', '%test result%');
            }),
            default => $query,
        };
    }

    private function geologicalRecordsCount(Project $project): int
    {
        if (!class_exists(GeologicalRecord::class)) {
            return 0;
        }

        try {
            return GeologicalRecord::query()
                ->whereHas('document', function (Builder $q) use ($project) {
                    $q->where('project_id', $project->id);
                })
                ->count();
        } catch (Throwable) {
            return 0;
        }
    }

    private function recentDocuments(Project $project, int $limit = 8)
    {
        return $this->documentQueryForProject($project)
            ->with([
                'uploader:id,name,email',
                'category:id,name,slug',
            ])
            ->select([
                'id',
                'project_id',
                'document_category_id',
                'uploaded_by',
                'document_code',
                'title',
                'document_type',
                'status',
                'scan_status',
                'sandbox_status',
                'security_level',
                'created_at',
                'updated_at',
            ])
            ->latest('updated_at')
            ->limit($limit)
            ->get();
    }

    private function recentGeologicalRecords(Project $project, int $limit = 8)
    {
        if (!class_exists(GeologicalRecord::class)) {
            return collect();
        }

        try {
            return GeologicalRecord::query()
                ->with([
                    'document:id,project_id,document_code,title,status,security_level,created_at',
                ])
                ->whereHas('document', function (Builder $q) use ($project) {
                    $q->where('project_id', $project->id);
                })
                ->latest('updated_at')
                ->limit($limit)
                ->get();
        } catch (Throwable) {
            return collect();
        }
    }

    private function securityAlertDocumentsQuery(Project $project): Builder
    {
        return $this->documentQueryForProject($project)
            ->where(function (Builder $q) {
                $q->whereIn('status', [
                        'quarantined',
                        'suspicious',
                        'infected',
                        'rejected',
                    ])
                    ->orWhereIn('scan_status', [
                        'suspicious',
                        'infected',
                        'failed',
                    ])
                    ->orWhereIn('sandbox_status', [
                        'unsafe',
                        'failed',
                    ]);
            });
    }

    private function securityAlerts(Project $project, int $limit = 8)
    {
        return $this->securityAlertDocumentsQuery($project)
            ->select([
                'id',
                'project_id',
                'document_code',
                'title',
                'status',
                'scan_status',
                'sandbox_status',
                'security_level',
                'updated_at',
                'created_at',
            ])
            ->latest('updated_at')
            ->limit($limit)
            ->get();
    }

    private function relatedCounts(Project $project): array
    {
        $documentsQuery = $this->documentQueryForProject($project);

        return [
            'documents' => (clone $documentsQuery)->count(),
            'active_documents' => (clone $documentsQuery)
                ->where('status', '!=', 'archived')
                ->count(),
            'archived_documents' => (clone $documentsQuery)
                ->where('status', 'archived')
                ->count(),
            'study_area_records' => $this->categorizedDocumentQuery($project, 'study_area')->count(),
            'sample_records' => $this->categorizedDocumentQuery($project, 'sample')->count(),
            'laboratory_records' => $this->categorizedDocumentQuery($project, 'laboratory')->count(),
            'geological_records' => $this->geologicalRecordsCount($project),
            'security_alerts' => $this->securityAlertDocumentsQuery($project)->count(),
        ];
    }

    private function projectPayload(Project $project, bool $includeRecords = false): array
    {
        $payload = $project->toArray();

        $payload['related_counts'] = $this->relatedCounts($project);

        $payload['workspace'] = [
            'description' => 'This project contains all related records for document, study area, sample, laboratory, geological, archive, and security workflows.',
            'quick_actions' => [
                'upload_document' => '/upload-document?project_id=' . $project->id,
                'documents' => '/alldocuments?project_id=' . $project->id,
                'search' => '/search?project_id=' . $project->id,
                'archives' => '/archives?project_id=' . $project->id,
                'study_areas' => '/study-areas?project_id=' . $project->id,
                'samples_laboratory' => '/samples-laboratory?project_id=' . $project->id,
                'reports' => '/reports?project_id=' . $project->id,
            ],
        ];

        if ($includeRecords) {
            $payload['related_records'] = [
                'recent_documents' => $this->recentDocuments($project, 10),
                'study_area_documents' => $this->categorizedDocumentQuery($project, 'study_area')
                    ->latest('updated_at')
                    ->limit(10)
                    ->get(),
                'sample_documents' => $this->categorizedDocumentQuery($project, 'sample')
                    ->latest('updated_at')
                    ->limit(10)
                    ->get(),
                'laboratory_documents' => $this->categorizedDocumentQuery($project, 'laboratory')
                    ->latest('updated_at')
                    ->limit(10)
                    ->get(),
                'geological_records' => $this->recentGeologicalRecords($project, 10),
                'security_alerts' => $this->securityAlerts($project, 10),
            ];
        }

        return $payload;
    }

    /**
     * Display all projects.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.',
            ], 401);
        }

        $query = $this->baseProjectQuery();

        $this->applyVisibility($query, $user, $request);
        $this->applyFilters($query, $request);

        $query->orderBy('created_at', 'desc');

        if ($request->filled('per_page')) {
            $perPage = min(max((int) $request->per_page, 1), 100);

            $projects = $query->paginate($perPage);

            $projects->getCollection()->transform(function (Project $project) {
                return $this->projectPayload($project, false);
            });

            return $this->sendResponse($projects, 'Projects retrieved successfully.');
        }

        $projects = $query->get()
            ->map(fn (Project $project) => $this->projectPayload($project, false))
            ->values();

        return $this->sendResponse($projects, 'Projects retrieved successfully.');
    }

    /**
     * Dashboard summary for Project Management page.
     */
    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.',
            ], 401);
        }

        $query = Project::query();

        $this->applyVisibility($query, $user, $request);

        $projectIds = (clone $query)->pluck('id');

        $documents = Document::query()
            ->whereIn('project_id', $projectIds);

        $data = [
            'total_projects' => (clone $query)->count(),
            'planned_projects' => (clone $query)->where('status', 'planned')->count(),
            'active_projects' => (clone $query)->where('status', 'active')->count(),
            'completed_projects' => (clone $query)->where('status', 'completed')->count(),
            'archived_projects' => (clone $query)->where('status', 'archived')->count(),
            'restricted_projects' => (clone $query)->where('security_level', 'restricted')->count(),
            'related_documents' => (clone $documents)->count(),
            'archived_documents' => (clone $documents)->where('status', 'archived')->count(),
            'security_alerts' => (clone $documents)
                ->where(function (Builder $q) {
                    $q->whereIn('status', ['quarantined', 'suspicious', 'infected', 'rejected'])
                        ->orWhereIn('scan_status', ['suspicious', 'infected', 'failed'])
                        ->orWhereIn('sandbox_status', ['unsafe', 'failed']);
                })
                ->count(),
        ];

        return $this->sendResponse($data, 'Project summary retrieved successfully.');
    }

    /**
     * Store new project.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.',
            ], 401);
        }

        if (!$this->canManageProjects($user)) {
            return $this->sendError('Permission Denied.', [
                'error' => 'Only Admin, Geologist, Project Manager, or Document Controller can create projects.',
            ], 403);
        }

        $validator = Validator::make($request->all(), $this->validationRules());

        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors(), 422);
        }

        $validated = $validator->validated();

        $project = DB::transaction(function () use ($validated, $user) {
            $projectType = $validated['project_type'] ?? 'other';
            $code = $validated['code'] ?? null;

            if (!$code) {
                $code = $this->generateProjectCode($projectType);
            }

            return Project::create([
                'created_by' => $user->id,
                'project_manager_id' => $validated['project_manager_id'] ?? null,
                'name' => $validated['name'],
                'code' => $code,
                'slug' => Str::slug($validated['name'] . '-' . $code),
                'description' => $validated['description'] ?? null,
                'location_name' => $validated['location_name'] ?? null,
                'latitude' => $validated['latitude'] ?? null,
                'longitude' => $validated['longitude'] ?? null,
                'project_type' => $validated['project_type'] ?? 'other',
                'status' => $validated['status'] ?? 'planned',
                'security_level' => $validated['security_level'] ?? 'internal',
                'start_date' => $validated['start_date'] ?? null,
                'end_date' => $validated['end_date'] ?? null,
                'metadata' => array_merge(
                    Arr::get($validated, 'metadata', []) ?? [],
                    [
                        'workspace_enabled' => true,
                        'records_container' => [
                            'documents',
                            'study_areas',
                            'samples',
                            'laboratory',
                            'geological_records',
                            'archives',
                            'security',
                        ],
                    ]
                ),
            ]);
        });

        $project->load([
            'creator:id,name,email',
            'manager:id,name,email',
        ]);

        return $this->sendResponse(
            $this->projectPayload($project, true),
            'Project created successfully.'
        );
    }

    /**
     * Show one project workspace.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.',
            ], 401);
        }

        $project = $this->findProjectForUser($id, $user, $request);

        if (!$project) {
            return $this->sendError('Not Found.', [
                'error' => 'Project not found or you are not allowed to view it.',
            ], 404);
        }

        return $this->sendResponse(
            $this->projectPayload($project, true),
            'Project retrieved successfully.'
        );
    }

    /**
     * Return all related records for a project.
     */
    public function records(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.',
            ], 401);
        }

        $project = $this->findProjectForUser($id, $user, $request);

        if (!$project) {
            return $this->sendError('Not Found.', [
                'error' => 'Project not found or you are not allowed to view it.',
            ], 404);
        }

        $data = [
            'project' => $project,
            'related_counts' => $this->relatedCounts($project),
            'records' => [
                'documents' => $this->documentQueryForProject($project)
                    ->latest('updated_at')
                    ->paginate(min(max((int) $request->get('per_page', 15), 1), 100)),
                'study_area_documents' => $this->categorizedDocumentQuery($project, 'study_area')
                    ->latest('updated_at')
                    ->limit(25)
                    ->get(),
                'sample_documents' => $this->categorizedDocumentQuery($project, 'sample')
                    ->latest('updated_at')
                    ->limit(25)
                    ->get(),
                'laboratory_documents' => $this->categorizedDocumentQuery($project, 'laboratory')
                    ->latest('updated_at')
                    ->limit(25)
                    ->get(),
                'geological_records' => $this->recentGeologicalRecords($project, 25),
                'security_alerts' => $this->securityAlerts($project, 25),
            ],
        ];

        return $this->sendResponse($data, 'Project records retrieved successfully.');
    }

    /**
     * Update project.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.',
            ], 401);
        }

        if (!$this->canManageProjects($user)) {
            return $this->sendError('Permission Denied.', [
                'error' => 'Only Admin, Geologist, Project Manager, or Document Controller can update projects.',
            ], 403);
        }

        $project = Project::find($id);

        if (!$project) {
            return $this->sendError('Not Found.', [
                'error' => 'Project not found.',
            ], 404);
        }

        $validator = Validator::make($request->all(), $this->validationRules($project));

        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors(), 422);
        }

        $validated = $validator->validated();

        DB::transaction(function () use ($project, $validated) {
            $project->fill([
                'project_manager_id' => array_key_exists('project_manager_id', $validated)
                    ? $validated['project_manager_id']
                    : $project->project_manager_id,
                'name' => $validated['name'] ?? $project->name,
                'code' => $validated['code'] ?? $project->code,
                'description' => array_key_exists('description', $validated)
                    ? $validated['description']
                    : $project->description,
                'location_name' => array_key_exists('location_name', $validated)
                    ? $validated['location_name']
                    : $project->location_name,
                'latitude' => array_key_exists('latitude', $validated)
                    ? $validated['latitude']
                    : $project->latitude,
                'longitude' => array_key_exists('longitude', $validated)
                    ? $validated['longitude']
                    : $project->longitude,
                'project_type' => $validated['project_type'] ?? $project->project_type,
                'status' => $validated['status'] ?? $project->status,
                'security_level' => $validated['security_level'] ?? $project->security_level,
                'start_date' => array_key_exists('start_date', $validated)
                    ? $validated['start_date']
                    : $project->start_date,
                'end_date' => array_key_exists('end_date', $validated)
                    ? $validated['end_date']
                    : $project->end_date,
                'metadata' => array_key_exists('metadata', $validated)
                    ? $validated['metadata']
                    : $project->metadata,
            ]);

            if (array_key_exists('name', $validated) || array_key_exists('code', $validated)) {
                $project->slug = Str::slug($project->name . '-' . $project->code);
            }

            $project->save();
        });

        $project->refresh()->load([
            'creator:id,name,email',
            'manager:id,name,email',
        ]);

        return $this->sendResponse(
            $this->projectPayload($project, true),
            'Project updated successfully.'
        );
    }

    /**
     * Archive project.
     */
    public function archive(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.',
            ], 401);
        }

        if (!$this->canManageProjects($user)) {
            return $this->sendError('Permission Denied.', [
                'error' => 'Only project managers can archive projects.',
            ], 403);
        }

        $project = Project::find($id);

        if (!$project) {
            return $this->sendError('Not Found.', [
                'error' => 'Project not found.',
            ], 404);
        }

        $project->update([
            'status' => 'archived',
            'archived_by' => $user->id,
            'archived_at' => now(),
            'archive_reason' => $request->input('reason'),
        ]);

        return $this->sendResponse(
            $this->projectPayload($project->refresh(), true),
            'Project archived successfully.'
        );
    }

    /**
     * Restore archived project.
     */
    public function restore(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.',
            ], 401);
        }

        if (!$this->canManageProjects($user)) {
            return $this->sendError('Permission Denied.', [
                'error' => 'Only project managers can restore projects.',
            ], 403);
        }

        $project = Project::find($id);

        if (!$project) {
            return $this->sendError('Not Found.', [
                'error' => 'Project not found.',
            ], 404);
        }

        $project->update([
            'status' => $request->input('status', 'active'),
            'restored_by' => $user->id,
            'restored_at' => now(),
            'restore_reason' => $request->input('reason'),
        ]);

        return $this->sendResponse(
            $this->projectPayload($project->refresh(), true),
            'Project restored successfully.'
        );
    }

    /**
     * Delete project.
     *
     * If a project has related records, it is archived instead of being deleted.
     * This protects the supervisor requirement: each project contains all related records.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.',
            ], 401);
        }

        if (!$this->canManageProjects($user)) {
            return $this->sendError('Permission Denied.', [
                'error' => 'Only Admin, Geologist, Project Manager, or Document Controller can delete projects.',
            ], 403);
        }

        $project = Project::find($id);

        if (!$project) {
            return $this->sendError('Not Found.', [
                'error' => 'Project not found.',
            ], 404);
        }

        $relatedDocumentsCount = $this->documentQueryForProject($project)->count();

        if ($relatedDocumentsCount > 0) {
            $project->update([
                'status' => 'archived',
                'archived_by' => $user->id,
                'archived_at' => now(),
                'archive_reason' => $request->input(
                    'reason',
                    'Archived automatically because the project contains related records.'
                ),
            ]);

            return $this->sendResponse(
                $this->projectPayload($project->refresh(), true),
                'Project has related records, so it was archived instead of deleted.'
            );
        }

        $project->delete();

        return $this->sendResponse([], 'Project deleted successfully.');
    }
}