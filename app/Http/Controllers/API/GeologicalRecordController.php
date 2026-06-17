<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController as BaseController;
use App\Models\Document;
use App\Models\GeologicalRecord;
use App\Models\MetadataSchema;
use App\Services\GeologicalMetadataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class GeologicalRecordController extends BaseController
{
    private function canViewGeologicalRecords($user): bool
    {
        return $user && (
            $user->isAdmin()
            || $user->hasPermission('view_documents')
            || in_array($user->role?->slug, [
                'admin',
                'geologist',
                'engineer',
                'project_manager',
                'document_controller',
                'security_officer',
                'auditor',
            ], true)
        );
    }

    private function canManageGeologicalRecords($user): bool
    {
        return $user && (
            $user->isAdmin()
            || in_array($user->role?->slug, [
                'admin',
                'geologist',
                'engineer',
                'project_manager',
                'document_controller',
            ], true)
        );
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.',
            ], 401);
        }

        if (!$this->canViewGeologicalRecords($user)) {
            return $this->sendError('Permission Denied.', [
                'error' => 'You are not allowed to view geological records.',
            ], 403);
        }

        $records = GeologicalRecord::with([
                'document:id,project_id,document_category_id,document_code,title,status,scan_status,sandbox_status,security_level,created_at',
                'document.project:id,name,code,status,security_level',
                'document.category:id,name,slug',
                'schema:id,name,slug,record_type,version,status',
                'creator:id,name,email',
                'updater:id,name,email',
            ])
            ->when($request->filled('document_id'), function ($query) use ($request) {
                $query->where('document_id', $request->document_id);
            })
            ->when($request->filled('metadata_schema_id'), function ($query) use ($request) {
                $query->where('metadata_schema_id', $request->metadata_schema_id);
            })
            ->when($request->filled('record_type'), function ($query) use ($request) {
                $query->where('record_type', $request->record_type);
            })
            ->when($request->filled('district'), function ($query) use ($request) {
                $query->where('district', $request->district);
            })
            ->when($request->filled('rock_type'), function ($query) use ($request) {
                $query->where('rock_type', 'LIKE', '%' . $request->rock_type . '%');
            })
            ->when($request->filled('mineral_name'), function ($query) use ($request) {
                $query->where('mineral_name', 'LIKE', '%' . $request->mineral_name . '%');
            })
            ->when($request->filled('borehole_code'), function ($query) use ($request) {
                $query->where('borehole_code', 'LIKE', '%' . $request->borehole_code . '%');
            })
            ->when($request->filled('project_id'), function ($query) use ($request) {
                $query->whereHas('document', function ($documentQuery) use ($request) {
                    $documentQuery->where('project_id', $request->project_id);
                });
            })
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = trim((string) $request->search);

                $query->where(function ($subQuery) use ($search) {
                    $subQuery->where('site_name', 'LIKE', '%' . $search . '%')
                        ->orWhere('survey_name', 'LIKE', '%' . $search . '%')
                        ->orWhere('geologist_name', 'LIKE', '%' . $search . '%')
                        ->orWhere('district', 'LIKE', '%' . $search . '%')
                        ->orWhere('geological_formation', 'LIKE', '%' . $search . '%')
                        ->orWhere('rock_type', 'LIKE', '%' . $search . '%')
                        ->orWhere('mineral_name', 'LIKE', '%' . $search . '%')
                        ->orWhere('commodity', 'LIKE', '%' . $search . '%')
                        ->orWhere('borehole_code', 'LIKE', '%' . $search . '%')
                        ->orWhere('fault_name', 'LIKE', '%' . $search . '%')
                        ->orWhere('notes', 'LIKE', '%' . $search . '%');
                });
            })
            ->latest()
            ->get();

        return $this->sendResponse(
            $records,
            'Geological records retrieved successfully.'
        );
    }

    public function store(
        Request $request,
        GeologicalMetadataService $metadataService
    ): JsonResponse {
        $user = $request->user();

        if (!$user) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.',
            ], 401);
        }

        if (!$this->canManageGeologicalRecords($user)) {
            return $this->sendError('Permission Denied.', [
                'error' => 'You are not allowed to create geological records.',
            ], 403);
        }

        $validator = $this->makeValidator($request);

        if ($validator->fails()) {
            return $this->sendError(
                'Validation Error.',
                $validator->errors(),
                422
            );
        }

        $document = Document::find($request->document_id);

        if (!$document) {
            return $this->sendError('Not Found.', [
                'error' => 'Document not found.',
            ], 404);
        }

        if (in_array($document->status, [
            'rejected',
            'infected',
            'blocked',
        ], true)) {
            return $this->sendError('Invalid Document.', [
                'error' => 'A rejected, infected, or blocked document cannot receive an official geological record.',
            ], 422);
        }

        if (GeologicalRecord::where('document_id', $document->id)->exists()) {
            return $this->sendError('Duplicate Geological Record.', [
                'error' => 'This document already has a geological record.',
            ], 422);
        }

        try {
            $schema = $this->resolveSchema($request);

            $customMetadata = $metadataService->validateAndNormalize(
                $schema,
                $request->input('custom_metadata', [])
            );

            $record = GeologicalRecord::create(
                array_merge(
                    $this->payloadFromRequest($request),
                    [
                        'document_id' => $document->id,
                        'metadata_schema_id' => $schema?->id,
                        'custom_metadata' => $customMetadata,
                        'metadata_version' => $schema?->version ?? 1,
                        'created_by' => $user->id,
                        'updated_by' => $user->id,
                    ]
                )
            );

            $record->load($this->relations());

            return $this->sendResponse(
                $record,
                'Geological record created successfully.'
            );
        } catch (ValidationException $exception) {
            return $this->sendError(
                'Metadata Validation Error.',
                $exception->errors(),
                422
            );
        } catch (Throwable $exception) {
            return $this->sendError('Create Failed.', [
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

        if (!$this->canViewGeologicalRecords($user)) {
            return $this->sendError('Permission Denied.', [
                'error' => 'You are not allowed to view geological records.',
            ], 403);
        }

        $record = GeologicalRecord::with($this->relations())->find($id);

        if (!$record) {
            return $this->sendError('Not Found.', [
                'error' => 'Geological record not found.',
            ], 404);
        }

        return $this->sendResponse(
            $record,
            'Geological record retrieved successfully.'
        );
    }

    public function update(
        Request $request,
        string $id,
        GeologicalMetadataService $metadataService
    ): JsonResponse {
        $user = $request->user();

        if (!$user) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.',
            ], 401);
        }

        if (!$this->canManageGeologicalRecords($user)) {
            return $this->sendError('Permission Denied.', [
                'error' => 'You are not allowed to update geological records.',
            ], 403);
        }

        $record = GeologicalRecord::find($id);

        if (!$record) {
            return $this->sendError('Not Found.', [
                'error' => 'Geological record not found.',
            ], 404);
        }

        $validator = $this->makeValidator($request, true);

        if ($validator->fails()) {
            return $this->sendError(
                'Validation Error.',
                $validator->errors(),
                422
            );
        }

        try {
            $schema = $request->has('metadata_schema_id')
                ? $this->resolveSchema($request)
                : $record->schema;

            $customMetadata = $request->has('custom_metadata')
                ? $metadataService->validateAndNormalize(
                    $schema,
                    $request->input('custom_metadata', [])
                )
                : $record->custom_metadata;

            $payload = $this->payloadFromRequest($request, true);

            $payload['metadata_schema_id'] = $schema?->id;
            $payload['custom_metadata'] = $customMetadata;
            $payload['metadata_version'] = $schema?->version
                ?? $record->metadata_version;
            $payload['updated_by'] = $user->id;

            $record->update($payload);

            $record->refresh()->load($this->relations());

            return $this->sendResponse(
                $record,
                'Geological record updated successfully.'
            );
        } catch (ValidationException $exception) {
            return $this->sendError(
                'Metadata Validation Error.',
                $exception->errors(),
                422
            );
        } catch (Throwable $exception) {
            return $this->sendError('Update Failed.', [
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

        if (!$this->canManageGeologicalRecords($user)) {
            return $this->sendError('Permission Denied.', [
                'error' => 'You are not allowed to archive geological records.',
            ], 403);
        }

        $record = GeologicalRecord::find($id);

        if (!$record) {
            return $this->sendError('Not Found.', [
                'error' => 'Geological record not found.',
            ], 404);
        }

        /*
        |--------------------------------------------------------------------------
        | Safe deletion
        |--------------------------------------------------------------------------
        | Soft delete keeps historical records and does not remove the document.
        */
        $record->delete();

        return $this->sendResponse(
            [],
            'Geological record archived successfully.'
        );
    }

    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.',
            ], 401);
        }

        if (!$this->canViewGeologicalRecords($user)) {
            return $this->sendError('Permission Denied.', [
                'error' => 'You are not allowed to view geological summaries.',
            ], 403);
        }

        $summary = [
            'total_records' => GeologicalRecord::count(),
            'records_by_type' => GeologicalRecord::query()
                ->selectRaw('record_type, COUNT(*) as total')
                ->groupBy('record_type')
                ->orderByDesc('total')
                ->get(),
            'records_by_district' => GeologicalRecord::query()
                ->whereNotNull('district')
                ->selectRaw('district, COUNT(*) as total')
                ->groupBy('district')
                ->orderByDesc('total')
                ->limit(20)
                ->get(),
            'borehole_records' => GeologicalRecord::whereNotNull('borehole_code')->count(),
            'mineral_records' => GeologicalRecord::whereNotNull('mineral_name')->count(),
            'groundwater_records' => GeologicalRecord::where(function ($query) {
                $query->whereNotNull('aquifer_name')
                    ->orWhereNotNull('water_level');
            })->count(),
            'mapped_records' => GeologicalRecord::whereNotNull('latitude')
                ->whereNotNull('longitude')
                ->count(),
        ];

        return $this->sendResponse(
            $summary,
            'Geological record summary retrieved successfully.'
        );
    }

    private function resolveSchema(Request $request): ?MetadataSchema
    {
        if (!$request->filled('metadata_schema_id')) {
            return null;
        }

        $schema = MetadataSchema::where('id', $request->metadata_schema_id)
            ->where('status', 'active')
            ->with('fields')
            ->first();

        if (!$schema) {
            throw ValidationException::withMessages([
                'metadata_schema_id' => [
                    'The selected metadata schema is inactive or does not exist.',
                ],
            ]);
        }

        return $schema;
    }

    private function makeValidator(
        Request $request,
        bool $isUpdate = false
    ) {
        return Validator::make($request->all(), [
            'document_id' => $isUpdate
                ? ['prohibited']
                : ['required', 'integer', 'exists:documents,id'],
            'metadata_schema_id' => [
                'nullable',
                'integer',
                'exists:metadata_schemas,id',
            ],
            'record_type' => [
                $isUpdate ? 'sometimes' : 'required',
                'string',
                Rule::in([
                    'general_geological_record',
                    'geological_report',
                    'geological_map',
                    'borehole',
                    'rock_sample',
                    'soil_profile',
                    'lithology_log',
                    'mineral_occurrence',
                    'laboratory_result',
                    'fault_structure',
                    'groundwater',
                    'geophysical_survey',
                    'geochemical_survey',
                    'exploration_permit',
                    'field_note',
                    'other',
                ]),
            ],

            'site_name' => ['nullable', 'string', 'max:255'],
            'survey_name' => ['nullable', 'string', 'max:255'],
            'survey_date' => ['nullable', 'date'],
            'geologist_name' => ['nullable', 'string', 'max:255'],
            'organization' => ['nullable', 'string', 'max:255'],

            'country' => ['nullable', 'string', 'max:100'],
            'province' => ['nullable', 'string', 'max:100'],
            'district' => ['nullable', 'string', 'max:100'],
            'sector' => ['nullable', 'string', 'max:100'],
            'cell' => ['nullable', 'string', 'max:100'],
            'village' => ['nullable', 'string', 'max:100'],

            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'elevation' => ['nullable', 'numeric'],
            'coordinate_reference_system' => ['nullable', 'string', 'max:100'],

            'geological_formation' => ['nullable', 'string', 'max:255'],
            'rock_type' => ['nullable', 'string', 'max:255'],
            'mineral_name' => ['nullable', 'string', 'max:255'],
            'commodity' => ['nullable', 'string', 'max:255'],
            'source_method' => ['nullable', 'string', 'max:255'],
            'data_quality' => [
                'nullable',
                Rule::in([
                    'verified',
                    'reviewed',
                    'provisional',
                    'estimated',
                    'unknown',
                ]),
            ],

            'borehole_code' => ['nullable', 'string', 'max:100'],
            'total_depth' => ['nullable', 'numeric', 'min:0'],
            'water_level' => ['nullable', 'numeric', 'min:0'],
            'aquifer_name' => ['nullable', 'string', 'max:255'],
            'aquifer_type' => ['nullable', 'string', 'max:255'],
            'yield_rate' => ['nullable', 'numeric', 'min:0'],

            'fault_name' => ['nullable', 'string', 'max:255'],
            'fault_type' => ['nullable', 'string', 'max:255'],
            'strike' => ['nullable', 'numeric', 'between:0,360'],
            'dip' => ['nullable', 'numeric', 'between:0,90'],
            'dip_direction' => ['nullable', 'string', 'max:100'],

            'custom_metadata' => ['nullable', 'array'],
            'notes' => ['nullable', 'string'],
        ]);
    }

    private function payloadFromRequest(
        Request $request,
        bool $onlyProvided = false
    ): array {
        $fields = [
            'record_type',

            'site_name',
            'survey_name',
            'survey_date',
            'geologist_name',
            'organization',

            'country',
            'province',
            'district',
            'sector',
            'cell',
            'village',

            'latitude',
            'longitude',
            'elevation',
            'coordinate_reference_system',

            'geological_formation',
            'rock_type',
            'mineral_name',
            'commodity',
            'source_method',
            'data_quality',

            'borehole_code',
            'total_depth',
            'water_level',
            'aquifer_name',
            'aquifer_type',
            'yield_rate',

            'fault_name',
            'fault_type',
            'strike',
            'dip',
            'dip_direction',

            'notes',
        ];

        $payload = [];

        foreach ($fields as $field) {
            if (!$onlyProvided || $request->has($field)) {
                $payload[$field] = $request->input($field);
            }
        }

        if (!$onlyProvided && empty($payload['country'])) {
            $payload['country'] = 'Rwanda';
        }

        if (
            !$onlyProvided
            && empty($payload['coordinate_reference_system'])
        ) {
            $payload['coordinate_reference_system'] = 'EPSG:4326';
        }

        return $payload;
    }

    private function relations(): array
    {
        return [
            'document:id,project_id,document_category_id,document_code,title,status,scan_status,sandbox_status,security_level,created_at',
            'document.project:id,name,code,status,security_level',
            'document.category:id,name,slug',
            'schema:id,name,slug,record_type,version,status',
            'schema.fields',
            'creator:id,name,email',
            'updater:id,name,email',
        ];
    }
}
