<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController as BaseController;
use App\Models\LaboratoryResult;
use App\Models\LaboratoryResultDocument;
use App\Models\SampleRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SampleLaboratoryController extends BaseController
{
    private const SAMPLE_STATUSES = [
        'collected',
        'in_transit',
        'received',
        'testing',
        'completed',
        'rejected',
    ];

    private const RESULT_STATUSES = [
        'pending',
        'received',
        'testing',
        'completed',
        'rejected',
        'cancelled',
    ];

    private const ALLOWED_DOCUMENT_EXTENSIONS = [
        'pdf',
        'doc',
        'docx',
        'xls',
        'xlsx',
        'csv',
        'txt',
        'jpg',
        'jpeg',
        'png',
        'webp',
        'tif',
        'tiff',
    ];

    private function canViewSamples($user): bool
    {
        return (bool) $user;
    }

    private function canManageSamples($user): bool
    {
        return $user && in_array($user->role?->slug, [
            'admin',
            'geologist',
            'project_manager',
            'document_controller',
            'laboratory_technician',
        ], true);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$this->canViewSamples($user)) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.',
            ], 401);
        }

        $query = SampleRecord::query()
            ->with([
                'project:id,name,code',
                'studyArea:id,name,code',
                'collector:id,name,email',
                'creator:id,name,email',
                'latestLaboratoryResult.documents',
                'laboratoryResults.documents',
            ])
            ->withCount([
                'laboratoryResults',
                'resultDocuments',
            ]);

        if ($request->boolean('with_archived')) {
            $query->withTrashed();
        }

        $query->when($request->filled('status'), function ($q) use ($request) {
            $q->where('status', $request->status);
        });

        $query->when($request->filled('project_id'), function ($q) use ($request) {
            $q->where('project_id', $request->project_id);
        });

        $query->when($request->filled('study_area_id'), function ($q) use ($request) {
            $q->where('study_area_id', $request->study_area_id);
        });

        $query->when($request->filled('sample_type'), function ($q) use ($request) {
            $q->where('sample_type', $request->sample_type);
        });

        $query->when($request->filled('collected_from'), function ($q) use ($request) {
            $q->whereDate('collected_date', '>=', $request->collected_from);
        });

        $query->when($request->filled('collected_to'), function ($q) use ($request) {
            $q->whereDate('collected_date', '<=', $request->collected_to);
        });

        $query->when($request->filled('laboratory'), function ($q) use ($request) {
            $q->whereHas('laboratoryResults', function ($subQuery) use ($request) {
                $subQuery->where('laboratory', 'LIKE', '%' . $request->laboratory . '%');
            });
        });

        $query->when($request->filled('result_status'), function ($q) use ($request) {
            $q->whereHas('laboratoryResults', function ($subQuery) use ($request) {
                $subQuery->where('result_status', $request->result_status);
            });
        });

        $query->when($request->filled('search'), function ($q) use ($request) {
            $keyword = '%' . $request->search . '%';

            $q->where(function ($subQuery) use ($keyword) {
                $subQuery->where('sample_code', 'LIKE', $keyword)
                    ->orWhere('sample_name', 'LIKE', $keyword)
                    ->orWhere('project_name', 'LIKE', $keyword)
                    ->orWhere('study_area_name', 'LIKE', $keyword)
                    ->orWhere('sample_type', 'LIKE', $keyword)
                    ->orWhere('material', 'LIKE', $keyword)
                    ->orWhere('district', 'LIKE', $keyword)
                    ->orWhere('sector', 'LIKE', $keyword)
                    ->orWhere('collected_by', 'LIKE', $keyword)
                    ->orWhereHas('laboratoryResults', function ($labQuery) use ($keyword) {
                        $labQuery->where('laboratory', 'LIKE', $keyword)
                            ->orWhere('lab_reference', 'LIKE', $keyword)
                            ->orWhere('test_type', 'LIKE', $keyword)
                            ->orWhere('result_summary', 'LIKE', $keyword);
                    });
            });
        });

        $perPage = (int) $request->get('per_page', 25);
        $perPage = min(max($perPage, 1), 200);

        $samples = $query
            ->orderByDesc('created_at')
            ->paginate($perPage);

        $samples->getCollection()->transform(fn (SampleRecord $sample) => $this->formatSample($sample));

        return $this->sendResponse($samples, 'Sample records retrieved successfully.');
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$this->canManageSamples($user)) {
            return $this->sendError('Permission Denied.', [
                'error' => 'You are not allowed to create sample records.',
            ], 403);
        }

        $this->mergeFrontendAliases($request);

        $validator = $this->validator($request);

        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors(), 422);
        }

        try {
            $sample = DB::transaction(function () use ($request, $user) {
                $sample = SampleRecord::create($this->samplePayload($request, $user));

                if ($this->requestHasLaboratoryData($request) || count($this->resultDocumentFiles($request)) > 0) {
                    $result = $sample->laboratoryResults()->create($this->laboratoryResultPayload($request, $user));
                    $this->storeResultDocuments($request, $sample, $result, $user);
                }

                return $this->loadSample($sample->id);
            });

            return $this->sendResponse(
                $this->formatSample($sample),
                'Sample and laboratory record created successfully.'
            );
        } catch (\Throwable $exception) {
            return $this->sendError('Create Failed.', [
                'error' => $exception->getMessage(),
            ], 500);
        }
    }

    public function show(string $id): JsonResponse
    {
        $user = request()->user();

        if (!$this->canViewSamples($user)) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.',
            ], 401);
        }

        $sample = $this->loadSample($id, request()->boolean('with_archived'));

        if (!$sample) {
            return $this->sendError('Not Found.', [
                'error' => 'Sample record not found.',
            ], 404);
        }

        return $this->sendResponse(
            $this->formatSample($sample),
            'Sample record retrieved successfully.'
        );
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        if (!$this->canManageSamples($user)) {
            return $this->sendError('Permission Denied.', [
                'error' => 'You are not allowed to update sample records.',
            ], 403);
        }

        $sample = SampleRecord::find($id);

        if (!$sample) {
            return $this->sendError('Not Found.', [
                'error' => 'Sample record not found.',
            ], 404);
        }

        $this->mergeFrontendAliases($request);

        $validator = $this->validator($request, true, $sample);

        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors(), 422);
        }

        try {
            $sample = DB::transaction(function () use ($request, $user, $sample) {
                $sample->update($this->samplePayload($request, $user, true, $sample));

                if ($this->requestHasLaboratoryData($request) || count($this->resultDocumentFiles($request)) > 0) {
                    $result = $sample->latestLaboratoryResult ?: $sample->laboratoryResults()->create([
                        'created_by' => $user->id,
                        'lab_reference' => $this->makeLabReference(),
                        'result_status' => 'pending',
                    ]);

                    $result->update($this->laboratoryResultPayload($request, $user, true, $result));
                    $this->storeResultDocuments($request, $sample, $result, $user);
                }

                return $this->loadSample($sample->id);
            });

            return $this->sendResponse(
                $this->formatSample($sample),
                'Sample and laboratory record updated successfully.'
            );
        } catch (\Throwable $exception) {
            return $this->sendError('Update Failed.', [
                'error' => $exception->getMessage(),
            ], 500);
        }
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        if (!$this->canManageSamples($user)) {
            return $this->sendError('Permission Denied.', [
                'error' => 'You are not allowed to archive sample records.',
            ], 403);
        }

        $sample = SampleRecord::find($id);

        if (!$sample) {
            return $this->sendError('Not Found.', [
                'error' => 'Sample record not found.',
            ], 404);
        }

        $sample->delete();

        return $this->sendResponse([], 'Sample record archived successfully.');
    }

    public function restore(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        if (!$this->canManageSamples($user)) {
            return $this->sendError('Permission Denied.', [
                'error' => 'You are not allowed to restore sample records.',
            ], 403);
        }

        $sample = SampleRecord::withTrashed()->find($id);

        if (!$sample) {
            return $this->sendError('Not Found.', [
                'error' => 'Sample record not found.',
            ], 404);
        }

        $sample->restore();

        $sample = $this->loadSample($sample->id);

        return $this->sendResponse(
            $this->formatSample($sample),
            'Sample record restored successfully.'
        );
    }

    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$this->canViewSamples($user)) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.',
            ], 401);
        }

        $summary = [
            'total_samples' => SampleRecord::count(),
            'collected_samples' => SampleRecord::where('status', 'collected')->count(),
            'in_transit_samples' => SampleRecord::where('status', 'in_transit')->count(),
            'received_samples' => SampleRecord::where('status', 'received')->count(),
            'testing_samples' => SampleRecord::where('status', 'testing')->count(),
            'completed_samples' => SampleRecord::where('status', 'completed')->count(),
            'rejected_samples' => SampleRecord::where('status', 'rejected')->count(),
            'mapped_samples' => SampleRecord::whereNotNull('latitude')
                ->whereNotNull('longitude')
                ->count(),
            'laboratory_results' => LaboratoryResult::count(),
            'completed_results' => LaboratoryResult::where('result_status', 'completed')->count(),
            'result_documents' => LaboratoryResultDocument::count(),
            'samples_by_type' => SampleRecord::query()
                ->whereNotNull('sample_type')
                ->selectRaw('sample_type, COUNT(*) as total')
                ->groupBy('sample_type')
                ->orderByDesc('total')
                ->limit(20)
                ->get(),
            'results_by_test_type' => LaboratoryResult::query()
                ->whereNotNull('test_type')
                ->selectRaw('test_type, COUNT(*) as total')
                ->groupBy('test_type')
                ->orderByDesc('total')
                ->limit(20)
                ->get(),
        ];

        return $this->sendResponse($summary, 'Sample laboratory summary retrieved successfully.');
    }

    public function storeLaboratoryResult(Request $request, string $sampleId): JsonResponse
    {
        $user = $request->user();

        if (!$this->canManageSamples($user)) {
            return $this->sendError('Permission Denied.', [
                'error' => 'You are not allowed to add laboratory results.',
            ], 403);
        }

        $sample = SampleRecord::find($sampleId);

        if (!$sample) {
            return $this->sendError('Not Found.', [
                'error' => 'Sample record not found.',
            ], 404);
        }

        $this->mergeFrontendAliases($request);

        $validator = $this->laboratoryValidator($request);

        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors(), 422);
        }

        try {
            $sample = DB::transaction(function () use ($request, $user, $sample) {
                $result = $sample->laboratoryResults()->create($this->laboratoryResultPayload($request, $user));
                $this->storeResultDocuments($request, $sample, $result, $user);
                $this->syncSampleStatusFromResult($sample, $result);

                return $this->loadSample($sample->id);
            });

            return $this->sendResponse(
                $this->formatSample($sample),
                'Laboratory result added successfully.'
            );
        } catch (\Throwable $exception) {
            return $this->sendError('Create Failed.', [
                'error' => $exception->getMessage(),
            ], 500);
        }
    }

    public function updateLaboratoryResult(Request $request, string $sampleId, string $resultId): JsonResponse
    {
        $user = $request->user();

        if (!$this->canManageSamples($user)) {
            return $this->sendError('Permission Denied.', [
                'error' => 'You are not allowed to update laboratory results.',
            ], 403);
        }

        $sample = SampleRecord::find($sampleId);
        $result = LaboratoryResult::where('sample_record_id', $sampleId)->find($resultId);

        if (!$sample || !$result) {
            return $this->sendError('Not Found.', [
                'error' => 'Sample or laboratory result not found.',
            ], 404);
        }

        $this->mergeFrontendAliases($request);

        $validator = $this->laboratoryValidator($request, true);

        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors(), 422);
        }

        try {
            $sample = DB::transaction(function () use ($request, $user, $sample, $result) {
                $result->update($this->laboratoryResultPayload($request, $user, true, $result));
                $this->storeResultDocuments($request, $sample, $result, $user);
                $this->syncSampleStatusFromResult($sample, $result);

                return $this->loadSample($sample->id);
            });

            return $this->sendResponse(
                $this->formatSample($sample),
                'Laboratory result updated successfully.'
            );
        } catch (\Throwable $exception) {
            return $this->sendError('Update Failed.', [
                'error' => $exception->getMessage(),
            ], 500);
        }
    }

    public function deleteLaboratoryResult(Request $request, string $sampleId, string $resultId): JsonResponse
    {
        $user = $request->user();

        if (!$this->canManageSamples($user)) {
            return $this->sendError('Permission Denied.', [
                'error' => 'You are not allowed to remove laboratory results.',
            ], 403);
        }

        $result = LaboratoryResult::where('sample_record_id', $sampleId)->find($resultId);

        if (!$result) {
            return $this->sendError('Not Found.', [
                'error' => 'Laboratory result not found.',
            ], 404);
        }

        $result->delete();

        return $this->sendResponse([], 'Laboratory result archived successfully.');
    }

    public function deleteResultDocument(Request $request, string $sampleId, string $resultId, string $documentId): JsonResponse
    {
        $user = $request->user();

        if (!$this->canManageSamples($user)) {
            return $this->sendError('Permission Denied.', [
                'error' => 'You are not allowed to delete result documents.',
            ], 403);
        }

        $document = LaboratoryResultDocument::where('sample_record_id', $sampleId)
            ->where('laboratory_result_id', $resultId)
            ->find($documentId);

        if (!$document) {
            return $this->sendError('Not Found.', [
                'error' => 'Result document not found.',
            ], 404);
        }

        if ($document->file_path && Storage::disk($document->file_disk ?: 'public')->exists($document->file_path)) {
            Storage::disk($document->file_disk ?: 'public')->delete($document->file_path);
        }

        $document->delete();

        return $this->sendResponse([], 'Result document deleted successfully.');
    }

    private function validator(Request $request, bool $isUpdate = false, ?SampleRecord $sample = null)
    {
        $rules = [
            'project_id' => ['nullable', 'integer'],
            'study_area_id' => ['nullable', 'integer'],
            'collector_user_id' => ['nullable', 'integer'],
            'sample_code' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('sample_records', 'sample_code')->ignore($sample?->id),
            ],
            'sample_name' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:255'],
            'project_name' => ['nullable', 'string', 'max:255'],
            'study_area_name' => ['nullable', 'string', 'max:255'],
            'sample_type' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:120'],
            'material' => ['nullable', 'string', 'max:120'],
            'collection_location' => ['nullable', 'string', 'max:255'],
            'province' => ['nullable', 'string', 'max:120'],
            'district' => ['nullable', 'string', 'max:120'],
            'sector' => ['nullable', 'string', 'max:120'],
            'cell' => ['nullable', 'string', 'max:120'],
            'village' => ['nullable', 'string', 'max:120'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'depth' => ['nullable', 'string', 'max:80'],
            'collection_method' => ['nullable', 'string', 'max:150'],
            'collected_by' => ['nullable', 'string', 'max:255'],
            'collected_date' => ['nullable', 'date'],
            'storage_condition' => ['nullable', 'string', 'max:255'],
            'chain_of_custody' => ['nullable', 'string'],
            'status' => ['nullable', Rule::in(self::SAMPLE_STATUSES)],
            'notes' => ['nullable', 'string'],
            'metadata' => ['nullable', 'array'],
        ];

        return $this->withDocumentValidation(
            Validator::make($request->all(), array_merge($rules, $this->laboratoryRules($isUpdate))),
            $request
        );
    }

    private function laboratoryValidator(Request $request, bool $isUpdate = false)
    {
        return $this->withDocumentValidation(
            Validator::make($request->all(), $this->laboratoryRules($isUpdate)),
            $request
        );
    }

    private function laboratoryRules(bool $isUpdate = false): array
    {
        return [
            'laboratory' => [$isUpdate ? 'sometimes' : 'nullable', 'string', 'max:255'],
            'lab_reference' => ['nullable', 'string', 'max:120'],
            'received_date' => ['nullable', 'date'],
            'test_type' => ['nullable', 'string', 'max:150'],
            'test_method' => ['nullable', 'string', 'max:255'],
            'tested_by' => ['nullable', 'string', 'max:255'],
            'test_date' => ['nullable', 'date'],
            'result_status' => ['nullable', Rule::in(self::RESULT_STATUSES)],
            'result_summary' => ['nullable', 'string'],
            'test_results' => ['nullable', 'array'],
            'interpretation' => ['nullable', 'string'],
            'recommendation' => ['nullable', 'string'],
            'result_notes' => ['nullable', 'string'],
            'result_documents' => ['nullable'],
            'result_documents.*' => ['file', 'max:51200'],
            'resultDocuments' => ['nullable'],
            'resultDocuments.*' => ['file', 'max:51200'],
        ];
    }

    private function withDocumentValidation($validator, Request $request)
    {
        $validator->after(function ($validator) use ($request) {
            foreach ($this->resultDocumentFiles($request) as $file) {
                $extension = strtolower($file->getClientOriginalExtension());

                if (!in_array($extension, self::ALLOWED_DOCUMENT_EXTENSIONS, true)) {
                    $validator->errors()->add(
                        'result_documents',
                        'Invalid result document type. Allowed types: ' . implode(', ', self::ALLOWED_DOCUMENT_EXTENSIONS)
                    );
                }
            }
        });

        return $validator;
    }

    private function samplePayload(Request $request, $user, bool $isUpdate = false, ?SampleRecord $sample = null): array
    {
        $payload = [];

        $fields = [
            'project_id',
            'study_area_id',
            'collector_user_id',
            'sample_code',
            'sample_name',
            'project_name',
            'study_area_name',
            'sample_type',
            'material',
            'collection_location',
            'province',
            'district',
            'sector',
            'cell',
            'village',
            'latitude',
            'longitude',
            'depth',
            'collection_method',
            'collected_by',
            'collected_date',
            'storage_condition',
            'chain_of_custody',
            'status',
            'notes',
            'metadata',
        ];

        foreach ($fields as $field) {
            if ($request->has($field)) {
                $payload[$field] = $request->input($field);
            }
        }

        if (!$isUpdate) {
            $payload['created_by'] = $user->id;
            $payload['sample_code'] = $payload['sample_code'] ?? $this->makeSampleCode();
            $payload['status'] = $payload['status'] ?? 'collected';
        }

        if ($isUpdate && empty($payload['sample_code']) && $sample) {
            unset($payload['sample_code']);
        }

        return $payload;
    }

    private function laboratoryResultPayload(Request $request, $user, bool $isUpdate = false, ?LaboratoryResult $result = null): array
    {
        $payload = [];

        $fields = [
            'laboratory',
            'lab_reference',
            'received_date',
            'test_type',
            'test_method',
            'tested_by',
            'test_date',
            'result_status',
            'result_summary',
            'test_results',
            'interpretation',
            'recommendation',
            'metadata',
        ];

        foreach ($fields as $field) {
            if ($request->has($field)) {
                $payload[$field] = $request->input($field);
            }
        }

        if ($request->has('result_notes')) {
            $payload['notes'] = $request->input('result_notes');
        }

        if (!$isUpdate) {
            $payload['created_by'] = $user->id;
            $payload['lab_reference'] = $payload['lab_reference'] ?? $this->makeLabReference();
            $payload['result_status'] = $payload['result_status'] ?? 'pending';
        }

        if ($isUpdate && empty($payload['lab_reference']) && $result) {
            unset($payload['lab_reference']);
        }

        return $payload;
    }

    private function storeResultDocuments(Request $request, SampleRecord $sample, LaboratoryResult $result, $user): void
    {
        $files = $this->resultDocumentFiles($request);

        foreach ($files as $file) {
            $extension = strtolower($file->getClientOriginalExtension());
            $sampleCode = Str::slug($sample->sample_code ?: ('sample-' . $sample->id));
            $storedFileName = 'LABDOC-' . now()->format('YmdHis') . '-' . strtoupper(Str::random(8)) . '.' . $extension;
            $folder = 'dms/laboratory-results/' . $sampleCode . '/' . now()->format('Y/m');

            $path = Storage::disk('public')->putFileAs($folder, $file, $storedFileName);

            LaboratoryResultDocument::create([
                'sample_record_id' => $sample->id,
                'laboratory_result_id' => $result->id,
                'uploaded_by' => $user->id,
                'document_type' => $request->input('document_type', 'laboratory_result_document'),
                'title' => $request->input('document_title') ?: pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
                'description' => $request->input('document_description'),
                'original_file_name' => $file->getClientOriginalName(),
                'stored_file_name' => $storedFileName,
                'file_path' => $path,
                'file_disk' => 'public',
                'file_extension' => $extension,
                'mime_type' => $file->getMimeType(),
                'file_size' => $file->getSize(),
                'sha256_hash' => hash_file('sha256', $file->getRealPath()),
                'metadata' => [
                    'source' => 'sample_laboratory_module',
                    'uploaded_at' => now()->toDateTimeString(),
                ],
            ]);
        }
    }

    private function resultDocumentFiles(Request $request): array
    {
        $files = [];

        foreach (['result_documents', 'resultDocuments'] as $key) {
            if ($request->hasFile($key)) {
                $value = $request->file($key);

                if (is_array($value)) {
                    $files = array_merge($files, $value);
                } else {
                    $files[] = $value;
                }
            }
        }

        return array_values(array_filter($files));
    }

    private function requestHasLaboratoryData(Request $request): bool
    {
        foreach ([
            'laboratory',
            'lab_reference',
            'received_date',
            'test_type',
            'test_method',
            'tested_by',
            'test_date',
            'result_status',
            'result_summary',
            'test_results',
            'interpretation',
            'recommendation',
            'result_notes',
        ] as $field) {
            if ($request->filled($field) || is_array($request->input($field))) {
                return true;
            }
        }

        return false;
    }

    private function syncSampleStatusFromResult(SampleRecord $sample, LaboratoryResult $result): void
    {
        if ($result->result_status === 'completed') {
            $sample->update(['status' => 'completed']);
            return;
        }

        if ($result->result_status === 'testing') {
            $sample->update(['status' => 'testing']);
            return;
        }

        if ($result->result_status === 'received') {
            $sample->update(['status' => 'received']);
        }
    }

    private function loadSample($id, bool $withTrashed = false): ?SampleRecord
    {
        $query = SampleRecord::query()
            ->with([
                'project:id,name,code',
                'studyArea:id,name,code',
                'collector:id,name,email',
                'creator:id,name,email',
                'latestLaboratoryResult.documents.uploader:id,name,email',
                'laboratoryResults.documents.uploader:id,name,email',
            ])
            ->withCount([
                'laboratoryResults',
                'resultDocuments',
            ]);

        if ($withTrashed) {
            $query->withTrashed();
        }

        return $query->find($id);
    }

    private function formatSample(?SampleRecord $sample): ?array
    {
        if (!$sample) {
            return null;
        }

        $latestResult = $sample->latestLaboratoryResult;

        return [
            'id' => $sample->id,
            'sample_code' => $sample->sample_code,
            'sampleCode' => $sample->sample_code,
            'sample_name' => $sample->sample_name,
            'sampleName' => $sample->sample_name,
            'project_id' => $sample->project_id,
            'project' => $sample->project?->name ?: ($sample->project_name ?: 'General Repository'),
            'project_name' => $sample->project_name,
            'study_area_id' => $sample->study_area_id,
            'studyArea' => $sample->studyArea?->name ?: ($sample->study_area_name ?: 'Unassigned Study Area'),
            'study_area_name' => $sample->study_area_name,
            'sample_type' => $sample->sample_type,
            'sampleType' => $sample->sample_type,
            'material' => $sample->material,
            'collection_location' => $sample->collection_location,
            'province' => $sample->province,
            'district' => $sample->district,
            'sector' => $sample->sector,
            'cell' => $sample->cell,
            'village' => $sample->village,
            'latitude' => $sample->latitude,
            'longitude' => $sample->longitude,
            'depth' => $sample->depth,
            'collection_method' => $sample->collection_method,
            'collected_by' => $sample->collector?->name ?: $sample->collected_by,
            'collectedBy' => $sample->collector?->name ?: $sample->collected_by,
            'collected_date' => $sample->collected_date?->format('Y-m-d'),
            'collectedDate' => $sample->collected_date?->format('Y-m-d'),
            'storage_condition' => $sample->storage_condition,
            'chain_of_custody' => $sample->chain_of_custody,
            'chainOfCustody' => $sample->chain_of_custody,
            'status' => $sample->status,
            'notes' => $sample->notes,
            'metadata' => $sample->metadata,
            'laboratory' => $latestResult?->laboratory,
            'lab_reference' => $latestResult?->lab_reference,
            'labReference' => $latestResult?->lab_reference,
            'received_date' => $latestResult?->received_date?->format('Y-m-d'),
            'test_type' => $latestResult?->test_type,
            'testType' => $latestResult?->test_type,
            'test_method' => $latestResult?->test_method,
            'testMethod' => $latestResult?->test_method,
            'tested_by' => $latestResult?->tested_by,
            'testedBy' => $latestResult?->tested_by,
            'test_date' => $latestResult?->test_date?->format('Y-m-d'),
            'testDate' => $latestResult?->test_date?->format('Y-m-d'),
            'result_status' => $latestResult?->result_status,
            'resultStatus' => $latestResult?->result_status,
            'result_summary' => $latestResult?->result_summary,
            'resultSummary' => $latestResult?->result_summary,
            'test_results' => $latestResult?->test_results,
            'interpretation' => $latestResult?->interpretation,
            'recommendation' => $latestResult?->recommendation,
            'laboratory_results_count' => $sample->laboratory_results_count ?? $sample->laboratoryResults()->count(),
            'result_documents_count' => $sample->result_documents_count ?? $sample->resultDocuments()->count(),
            'resultDocumentsCount' => $sample->result_documents_count ?? $sample->resultDocuments()->count(),
            'laboratory_results' => $sample->laboratoryResults->map(fn (LaboratoryResult $result) => $this->formatLaboratoryResult($result))->values(),
            'result_documents' => $sample->resultDocuments->map(fn (LaboratoryResultDocument $document) => $this->formatResultDocument($document))->values(),
            'created_by' => $sample->creator,
            'created_at' => $sample->created_at,
            'updated_at' => $sample->updated_at,
            'deleted_at' => $sample->deleted_at,
        ];
    }

    private function formatLaboratoryResult(LaboratoryResult $result): array
    {
        return [
            'id' => $result->id,
            'sample_record_id' => $result->sample_record_id,
            'laboratory' => $result->laboratory,
            'lab_reference' => $result->lab_reference,
            'labReference' => $result->lab_reference,
            'received_date' => $result->received_date?->format('Y-m-d'),
            'test_type' => $result->test_type,
            'testType' => $result->test_type,
            'test_method' => $result->test_method,
            'testMethod' => $result->test_method,
            'tested_by' => $result->tested_by,
            'testedBy' => $result->tested_by,
            'test_date' => $result->test_date?->format('Y-m-d'),
            'testDate' => $result->test_date?->format('Y-m-d'),
            'result_status' => $result->result_status,
            'resultStatus' => $result->result_status,
            'result_summary' => $result->result_summary,
            'resultSummary' => $result->result_summary,
            'test_results' => $result->test_results,
            'interpretation' => $result->interpretation,
            'recommendation' => $result->recommendation,
            'notes' => $result->notes,
            'documents' => $result->documents->map(fn (LaboratoryResultDocument $document) => $this->formatResultDocument($document))->values(),
            'created_at' => $result->created_at,
            'updated_at' => $result->updated_at,
        ];
    }

    private function formatResultDocument(LaboratoryResultDocument $document): array
    {
        return [
            'id' => $document->id,
            'sample_record_id' => $document->sample_record_id,
            'laboratory_result_id' => $document->laboratory_result_id,
            'document_id' => $document->document_id,
            'document_type' => $document->document_type,
            'title' => $document->title,
            'description' => $document->description,
            'original_file_name' => $document->original_file_name,
            'originalFileName' => $document->original_file_name,
            'file_path' => $document->file_path,
            'file_extension' => $document->file_extension,
            'mime_type' => $document->mime_type,
            'mimeType' => $document->mime_type,
            'file_size' => $document->file_size,
            'fileSize' => $document->file_size,
            'sha256_hash' => $document->sha256_hash,
            'url' => $document->url,
            'uploaded_by' => $document->uploader,
            'created_at' => $document->created_at,
            'updated_at' => $document->updated_at,
        ];
    }

    private function makeSampleCode(): string
    {
        do {
            $code = 'SMP-' . now()->format('Y') . '-' . strtoupper(Str::random(6));
        } while (SampleRecord::where('sample_code', $code)->exists());

        return $code;
    }

    private function makeLabReference(): string
    {
        return 'LAB-' . now()->format('YmdHis') . '-' . strtoupper(Str::random(5));
    }

    private function mergeFrontendAliases(Request $request): void
    {
        $aliases = [
            'sampleCode' => 'sample_code',
            'sampleName' => 'sample_name',
            'projectId' => 'project_id',
            'project' => 'project_name',
            'studyAreaId' => 'study_area_id',
            'studyArea' => 'study_area_name',
            'sampleType' => 'sample_type',
            'collectionLocation' => 'collection_location',
            'collectionMethod' => 'collection_method',
            'collectedBy' => 'collected_by',
            'collectedDate' => 'collected_date',
            'collectorUserId' => 'collector_user_id',
            'storageCondition' => 'storage_condition',
            'chainOfCustody' => 'chain_of_custody',
            'labReference' => 'lab_reference',
            'receivedDate' => 'received_date',
            'testType' => 'test_type',
            'testMethod' => 'test_method',
            'testedBy' => 'tested_by',
            'testDate' => 'test_date',
            'resultStatus' => 'result_status',
            'resultSummary' => 'result_summary',
            'testResults' => 'test_results',
            'resultNotes' => 'result_notes',
        ];

        $data = [];

        foreach ($aliases as $frontend => $backend) {
            if ($request->has($frontend) && !$request->has($backend)) {
                $data[$backend] = $request->input($frontend);
            }
        }

        if ($request->filled('district') && !$request->filled('collection_location')) {
            $locationParts = array_filter([
                $request->input('district'),
                $request->input('sector'),
                $request->input('cell'),
                $request->input('village'),
            ]);

            $data['collection_location'] = implode(', ', $locationParts);
        }

        if (!empty($data)) {
            $request->merge($data);
        }
    }
}