<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController as BaseController;
use App\Models\Project;
use App\Models\StudyArea;
use App\Models\StudyAreaPhoto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

class StudyAreaController extends BaseController
{
    private function allowedManagerRoles(): array
    {
        return [
            'admin',
            'project_manager',
            'document_controller',
            'geologist',
            'engineer',
        ];
    }

    private function allowedViewerRoles(): array
    {
        return [
            'admin',
            'project_manager',
            'document_controller',
            'geologist',
            'engineer',
            'auditor',
            'viewer',
        ];
    }

    private function canViewStudyAreas($user): bool
    {
        return $user && (
            $user->isAdmin()
            || $user->hasPermission('view_documents')
            || in_array($user->role?->slug, $this->allowedViewerRoles(), true)
        );
    }

    private function canManageStudyAreas($user): bool
    {
        return $user && (
            $user->isAdmin()
            || $user->hasPermission('manage_projects')
            || in_array($user->role?->slug, $this->allowedManagerRoles(), true)
        );
    }

    private function relations(): array
    {
        return [
            'project:id,name,code,status,security_level',
            'creator:id,name,email',
            'updater:id,name,email',
            'photos:id,study_area_id,uploaded_by,caption,original_file_name,stored_file_name,file_path,disk,mime_type,extension,file_size,captured_at,sort_order,created_at',
            'photos.uploader:id,name,email',
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.',
            ], 401);
        }

        if (!$this->canViewStudyAreas($user)) {
            return $this->sendError('Permission Denied.', [
                'error' => 'You are not allowed to view study areas.',
            ], 403);
        }

        $query = StudyArea::with($this->relations())
            ->withCount('photos');

        if (!$this->canManageStudyAreas($user)) {
            $query->where('status', '!=', 'archived');
        }

        $query->when($request->filled('status'), function ($q) use ($request) {
            $q->where('status', $request->status);
        });

        $query->when($request->filled('project_id'), function ($q) use ($request) {
            $q->where('project_id', $request->project_id);
        });

        $query->when($request->filled('district'), function ($q) use ($request) {
            $q->where('district', 'LIKE', '%' . trim((string) $request->district) . '%');
        });

        $query->when($request->filled('search'), function ($q) use ($request) {
            $search = trim((string) $request->search);

            $q->where(function ($subQuery) use ($search) {
                $subQuery->where('name', 'LIKE', '%' . $search . '%')
                    ->orWhere('code', 'LIKE', '%' . $search . '%')
                    ->orWhere('project_name', 'LIKE', '%' . $search . '%')
                    ->orWhere('description', 'LIKE', '%' . $search . '%')
                    ->orWhere('province', 'LIKE', '%' . $search . '%')
                    ->orWhere('district', 'LIKE', '%' . $search . '%')
                    ->orWhere('sector', 'LIKE', '%' . $search . '%')
                    ->orWhere('cell', 'LIKE', '%' . $search . '%')
                    ->orWhere('village', 'LIKE', '%' . $search . '%')
                    ->orWhere('map_title', 'LIKE', '%' . $search . '%')
                    ->orWhere('map_type', 'LIKE', '%' . $search . '%')
                    ->orWhere('map_reference', 'LIKE', '%' . $search . '%')
                    ->orWhere('field_team', 'LIKE', '%' . $search . '%')
                    ->orWhere('access_route', 'LIKE', '%' . $search . '%');
            });
        });

        $perPage = (int) $request->input('per_page', 50);
        $perPage = max(1, min($perPage, 200));

        if ($request->boolean('paginate')) {
            $studyAreas = $query
                ->orderByDesc('created_at')
                ->paginate($perPage);
        } else {
            $studyAreas = $query
                ->orderByDesc('created_at')
                ->limit($perPage)
                ->get();
        }

        return $this->sendResponse($studyAreas, 'Study areas retrieved successfully.');
    }

    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.',
            ], 401);
        }

        if (!$this->canViewStudyAreas($user)) {
            return $this->sendError('Permission Denied.', [
                'error' => 'You are not allowed to view study area summary.',
            ], 403);
        }

        $baseQuery = StudyArea::query();

        if (!$this->canManageStudyAreas($user)) {
            $baseQuery->where('status', '!=', 'archived');
        }

        $summary = [
            'total' => (clone $baseQuery)->count(),
            'active' => (clone $baseQuery)->where('status', 'active')->count(),
            'planned' => (clone $baseQuery)->where('status', 'planned')->count(),
            'under_review' => (clone $baseQuery)->where('status', 'under_review')->count(),
            'archived' => (clone $baseQuery)->where('status', 'archived')->count(),
            'gps_verified' => (clone $baseQuery)->whereNotNull('latitude')->whereNotNull('longitude')->count(),
            'with_photos' => (clone $baseQuery)->whereHas('photos')->count(),
            'photos' => StudyAreaPhoto::count(),
        ];

        return $this->sendResponse($summary, 'Study area summary retrieved successfully.');
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.',
            ], 401);
        }

        if (!$this->canManageStudyAreas($user)) {
            return $this->sendError('Permission Denied.', [
                'error' => 'Only Admin, Project Manager, Document Controller, Geologist, or Engineer can create study areas.',
            ], 403);
        }

        $validator = $this->validator($request);

        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors(), 422);
        }

        try {
            $studyArea = DB::transaction(function () use ($request, $user) {
                $payload = $this->payloadFromRequest($request);
                $payload['created_by'] = $user->id;
                $payload['updated_by'] = $user->id;
                $payload['code'] = $payload['code'] ?: $this->generateCode($payload['district'] ?? null);
                $payload['slug'] = $this->generateUniqueSlug($payload['name']);

                $studyArea = StudyArea::create($payload);
                $this->storePhotos($request, $studyArea, $user->id);

                return $studyArea->load($this->relations());
            });

            return $this->sendResponse($studyArea, 'Study area created successfully.');
        } catch (Throwable $exception) {
            return $this->sendError('Server Error.', [
                'error' => $exception->getMessage(),
            ], 500);
        }
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.',
            ], 401);
        }

        if (!$this->canViewStudyAreas($user)) {
            return $this->sendError('Permission Denied.', [
                'error' => 'You are not allowed to view this study area.',
            ], 403);
        }

        $studyArea = StudyArea::with($this->relations())->find($id);

        if (!$studyArea) {
            return $this->sendError('Not Found.', [
                'error' => 'Study area not found.',
            ], 404);
        }

        if (!$this->canManageStudyAreas($user) && $studyArea->status === 'archived') {
            return $this->sendError('Permission Denied.', [
                'error' => 'You are not allowed to view archived study areas.',
            ], 403);
        }

        return $this->sendResponse($studyArea, 'Study area retrieved successfully.');
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.',
            ], 401);
        }

        if (!$this->canManageStudyAreas($user)) {
            return $this->sendError('Permission Denied.', [
                'error' => 'Only Admin, Project Manager, Document Controller, Geologist, or Engineer can update study areas.',
            ], 403);
        }

        $studyArea = StudyArea::find($id);

        if (!$studyArea) {
            return $this->sendError('Not Found.', [
                'error' => 'Study area not found.',
            ], 404);
        }

        $validator = $this->validator($request, $studyArea);

        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors(), 422);
        }

        try {
            $studyArea = DB::transaction(function () use ($request, $user, $studyArea) {
                $payload = $this->payloadFromRequest($request, true);
                $payload['updated_by'] = $user->id;

                if (isset($payload['name']) && $payload['name'] !== $studyArea->name) {
                    $payload['slug'] = $this->generateUniqueSlug($payload['name'], $studyArea->id);
                }

                if (array_key_exists('code', $payload) && !$payload['code']) {
                    unset($payload['code']);
                }

                $studyArea->update($payload);
                $this->storePhotos($request, $studyArea, $user->id);

                return $studyArea->fresh($this->relations());
            });

            return $this->sendResponse($studyArea, 'Study area updated successfully.');
        } catch (Throwable $exception) {
            return $this->sendError('Server Error.', [
                'error' => $exception->getMessage(),
            ], 500);
        }
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.',
            ], 401);
        }

        if (!$this->canManageStudyAreas($user)) {
            return $this->sendError('Permission Denied.', [
                'error' => 'Only Admin, Project Manager, Document Controller, Geologist, or Engineer can delete study areas.',
            ], 403);
        }

        $studyArea = StudyArea::find($id);

        if (!$studyArea) {
            return $this->sendError('Not Found.', [
                'error' => 'Study area not found.',
            ], 404);
        }

        $studyArea->delete();

        return $this->sendResponse([], 'Study area deleted successfully.');
    }

    public function restore(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.',
            ], 401);
        }

        if (!$this->canManageStudyAreas($user)) {
            return $this->sendError('Permission Denied.', [
                'error' => 'Only allowed managers can restore study areas.',
            ], 403);
        }

        $studyArea = StudyArea::withTrashed()->find($id);

        if (!$studyArea) {
            return $this->sendError('Not Found.', [
                'error' => 'Study area not found.',
            ], 404);
        }

        $studyArea->restore();
        $studyArea->update([
            'status' => $studyArea->status === 'archived' ? 'under_review' : $studyArea->status,
            'updated_by' => $user->id,
        ]);

        return $this->sendResponse(
            $studyArea->fresh($this->relations()),
            'Study area restored successfully.'
        );
    }

    public function deletePhoto(Request $request, string $studyAreaId, string $photoId): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.',
            ], 401);
        }

        if (!$this->canManageStudyAreas($user)) {
            return $this->sendError('Permission Denied.', [
                'error' => 'Only allowed managers can delete study area photos.',
            ], 403);
        }

        $photo = StudyAreaPhoto::where('study_area_id', $studyAreaId)
            ->where('id', $photoId)
            ->first();

        if (!$photo) {
            return $this->sendError('Not Found.', [
                'error' => 'Study area photo not found.',
            ], 404);
        }

        if ($photo->file_path && Storage::disk($photo->disk ?: 'public')->exists($photo->file_path)) {
            Storage::disk($photo->disk ?: 'public')->delete($photo->file_path);
        }

        $photo->delete();

        return $this->sendResponse([], 'Study area photo deleted successfully.');
    }

    private function validator(Request $request, ?StudyArea $studyArea = null)
    {
        $isUpdate = $studyArea !== null;

        return Validator::make($request->all(), [
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'project_name' => ['nullable', 'string', 'max:255'],
            'project' => ['nullable', 'string', 'max:255'],

            'name' => [$isUpdate ? 'sometimes' : 'required', 'required', 'string', 'max:255'],
            'code' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('study_areas', 'code')->ignore($studyArea?->id),
            ],
            'description' => ['nullable', 'string'],

            'province' => ['nullable', 'string', 'max:100'],
            'district' => ['nullable', 'string', 'max:100'],
            'sector' => ['nullable', 'string', 'max:100'],
            'cell' => ['nullable', 'string', 'max:100'],
            'village' => ['nullable', 'string', 'max:100'],
            'location_name' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'elevation' => ['nullable', 'string', 'max:100'],
            'area_size' => ['nullable', 'string', 'max:100'],
            'areaSize' => ['nullable', 'string', 'max:100'],

            'map_title' => ['nullable', 'string', 'max:255'],
            'mapTitle' => ['nullable', 'string', 'max:255'],
            'map_type' => ['nullable', 'string', 'max:255'],
            'mapType' => ['nullable', 'string', 'max:255'],
            'map_reference' => ['nullable', 'string', 'max:255'],
            'mapReference' => ['nullable', 'string', 'max:255'],
            'map_scale' => ['nullable', 'string', 'max:100'],
            'mapScale' => ['nullable', 'string', 'max:100'],
            'coordinate_system' => ['nullable', 'string', 'max:150'],
            'coordinateSystem' => ['nullable', 'string', 'max:150'],
            'location_accuracy' => ['nullable', 'string', 'max:150'],
            'locationAccuracy' => ['nullable', 'string', 'max:150'],
            'access_route' => ['nullable', 'string'],
            'accessRoute' => ['nullable', 'string'],
            'field_team' => ['nullable', 'string', 'max:255'],
            'fieldTeam' => ['nullable', 'string', 'max:255'],

            'status' => ['nullable', Rule::in(['planned', 'active', 'under_review', 'archived'])],
            'last_surveyed' => ['nullable', 'date'],
            'lastSurveyed' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'metadata' => ['nullable', 'array'],

            'photos' => ['nullable', 'array'],
            'photos.*' => ['file', 'image', 'max:10240'],
            'photo_captions' => ['nullable', 'array'],
            'photo_captions.*' => ['nullable', 'string', 'max:255'],
        ]);
    }

    private function payloadFromRequest(Request $request, bool $onlyProvided = false): array
    {
        $map = [
            'project_id' => 'project_id',
            'project_name' => ['project_name', 'project'],
            'name' => 'name',
            'code' => 'code',
            'description' => 'description',
            'province' => 'province',
            'district' => 'district',
            'sector' => 'sector',
            'cell' => 'cell',
            'village' => 'village',
            'location_name' => 'location_name',
            'latitude' => 'latitude',
            'longitude' => 'longitude',
            'elevation' => 'elevation',
            'area_size' => ['area_size', 'areaSize'],
            'map_title' => ['map_title', 'mapTitle'],
            'map_type' => ['map_type', 'mapType'],
            'map_reference' => ['map_reference', 'mapReference'],
            'map_scale' => ['map_scale', 'mapScale'],
            'coordinate_system' => ['coordinate_system', 'coordinateSystem'],
            'location_accuracy' => ['location_accuracy', 'locationAccuracy'],
            'access_route' => ['access_route', 'accessRoute'],
            'field_team' => ['field_team', 'fieldTeam'],
            'status' => 'status',
            'last_surveyed' => ['last_surveyed', 'lastSurveyed'],
            'notes' => 'notes',
            'metadata' => 'metadata',
        ];

        $payload = [];

        foreach ($map as $column => $requestKeys) {
            $keys = is_array($requestKeys) ? $requestKeys : [$requestKeys];

            foreach ($keys as $key) {
                if ($request->has($key)) {
                    $payload[$column] = $request->input($key);
                    break;
                }
            }

            if (!$onlyProvided && !array_key_exists($column, $payload)) {
                $payload[$column] = null;
            }
        }

        if (!$onlyProvided) {
            $payload['status'] = $payload['status'] ?: 'planned';
            $payload['coordinate_system'] = $payload['coordinate_system'] ?: 'WGS 84';
            $payload['location_accuracy'] = $payload['location_accuracy'] ?: 'Not verified';
        }

        if (empty($payload['project_name']) && !empty($payload['project_id'])) {
            $project = Project::select('id', 'name')->find($payload['project_id']);
            $payload['project_name'] = $project?->name;
        }

        if (empty($payload['location_name'])) {
            $locationParts = array_filter([
                $payload['village'] ?? null,
                $payload['cell'] ?? null,
                $payload['sector'] ?? null,
                $payload['district'] ?? null,
                $payload['province'] ?? null,
            ]);

            if (!empty($locationParts)) {
                $payload['location_name'] = implode(', ', $locationParts);
            }
        }

        return $payload;
    }

    private function generateCode(?string $district = null): string
    {
        $prefix = 'SA';

        if ($district) {
            $districtPart = strtoupper(Str::substr(preg_replace('/[^A-Za-z]/', '', $district), 0, 3));
            $prefix .= '-' . ($districtPart ?: 'GEN');
        } else {
            $prefix .= '-GEN';
        }

        do {
            $code = $prefix . '-' . now()->format('Y') . '-' . strtoupper(Str::random(4));
        } while (StudyArea::where('code', $code)->exists());

        return $code;
    }

    private function generateUniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'study-area';
        $slug = $base;
        $counter = 2;

        while (
            StudyArea::where('slug', $slug)
                ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $slug = $base . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    private function storePhotos(Request $request, StudyArea $studyArea, int $userId): void
    {
        if (!$request->hasFile('photos')) {
            return;
        }

        $captions = $request->input('photo_captions', []);
        $currentMaxSort = (int) $studyArea->photos()->max('sort_order');

        foreach ($request->file('photos') as $index => $photoFile) {
            if (!$photoFile || !$photoFile->isValid()) {
                continue;
            }

            $path = $photoFile->store('study-areas/photos/' . $studyArea->id, 'public');

            $studyArea->photos()->create([
                'uploaded_by' => $userId,
                'caption' => $captions[$index] ?? null,
                'original_file_name' => $photoFile->getClientOriginalName(),
                'stored_file_name' => basename($path),
                'file_path' => $path,
                'disk' => 'public',
                'mime_type' => $photoFile->getClientMimeType(),
                'extension' => $photoFile->getClientOriginalExtension(),
                'file_size' => $photoFile->getSize() ?: 0,
                'captured_at' => now(),
                'sort_order' => $currentMaxSort + $index + 1,
                'metadata' => [
                    'source' => 'study_area_management_page',
                ],
            ]);
        }
    }
}