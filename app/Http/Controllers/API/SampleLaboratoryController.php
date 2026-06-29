<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\LaboratoryResult;
use App\Models\LaboratoryResultDocument;
use App\Models\Project;
use App\Models\SampleRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class SampleLaboratoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->query('per_page', 20);
        $perPage = $perPage > 0 ? min($perPage, 200) : 20;

        $query = SampleRecord::query()
            ->with([
                'project',
                'studyArea',
                'collector',
                'latestLaboratoryResult',
                'resultDocuments',
            ])
            ->withCount('resultDocuments')
            ->latest();

        if ($request->filled('status')) {
            $status = (string) $request->query('status');

            $query->where(function ($builder) use ($status) {
                $builder->where('status', $status)
                    ->orWhereHas('laboratoryResults', function ($resultQuery) use ($status) {
                        $resultQuery->where('result_status', $status);
                    });
            });
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->query('search'));

            $query->where(function ($builder) use ($search) {
                $builder
                    ->where('sample_code', 'like', "%{$search}%")
                    ->orWhere('sample_name', 'like', "%{$search}%")
                    ->orWhere('project_name', 'like', "%{$search}%")
                    ->orWhere('study_area_name', 'like', "%{$search}%")
                    ->orWhere('sample_type', 'like', "%{$search}%")
                    ->orWhere('material', 'like', "%{$search}%")
                    ->orWhere('collection_location', 'like', "%{$search}%")
                    ->orWhere('district', 'like', "%{$search}%")
                    ->orWhere('sector', 'like', "%{$search}%")
                    ->orWhere('collected_by', 'like', "%{$search}%");
            });
        }

        return response()->json([
            'success' => true,
            'message' => 'Samples loaded successfully.',
            'data' => $query->paginate($perPage),
        ]);
    }

    public function projects(Request $request): JsonResponse
    {
        $query = Project::query()->latest();

        if ($request->filled('search')) {
            $search = trim((string) $request->query('search'));

            $query->where(function ($builder) use ($search) {
                foreach (['name', 'title', 'project_name', 'code', 'description'] as $column) {
                    if (Schema::hasColumn('projects', $column)) {
                        $builder->orWhere($column, 'like', "%{$search}%");
                    }
                }
            });
        }

        $projects = $query
            ->limit(500)
            ->get()
            ->map(function ($project) {
                return [
                    'id' => $project->id,
                    'name' => $this->getProjectDisplayName($project),
                ];
            })
            ->values();

        return response()->json([
            'success' => true,
            'message' => 'Projects loaded successfully.',
            'data' => $projects,
        ]);
    }

    public function summary(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Samples laboratory summary loaded successfully.',
            'data' => [
                'total_samples' => SampleRecord::count(),
                'collected_samples' => SampleRecord::where('status', 'collected')->count(),
                'in_transit_samples' => SampleRecord::where('status', 'in_transit')->count(),
                'received_samples' => SampleRecord::where('status', 'received')->count(),
                'testing_samples' => SampleRecord::where('status', 'testing')->count(),
                'completed_samples' => SampleRecord::where('status', 'completed')->count(),
                'rejected_samples' => SampleRecord::where('status', 'rejected')->count(),
                'laboratory_results' => LaboratoryResult::count(),
                'completed_results' => LaboratoryResult::where('result_status', 'completed')->count(),
                'result_documents' => LaboratoryResultDocument::count(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'project_id' => ['required', 'integer', 'exists:projects,id'],

            'sample_name' => ['nullable', 'string', 'max:255'],
            'study_area_id' => ['nullable', 'integer'],
            'study_area_name' => ['nullable', 'string', 'max:255'],
            'sample_type' => ['required', 'string', 'max:150'],
            'material' => ['nullable', 'string', 'max:150'],

            'collection_location' => ['required', 'string', 'max:1000'],
            'google_map_location' => ['nullable', 'string', 'max:1000'],
            'district' => ['nullable', 'string', 'max:150'],
            'sector' => ['nullable', 'string', 'max:150'],

            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],

            'depth' => ['nullable', 'string', 'max:100'],
            'collected_by' => ['required', 'string', 'max:255'],
            'collected_date' => ['required', 'date'],
            'chain_of_custody' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string'],

            'laboratory' => ['nullable', 'string', 'max:255'],
            'received_date' => ['nullable', 'date'],
            'test_type' => ['nullable', 'string', 'max:150'],
            'test_method' => ['nullable', 'string', 'max:150'],
            'tested_by' => ['nullable', 'string', 'max:255'],
            'test_date' => ['nullable', 'date'],
            'result_status' => ['nullable', 'string', 'max:50'],
            'result_summary' => ['nullable', 'string'],
            'interpretation' => ['nullable', 'string'],
            'test_results' => ['nullable', 'array'],

            'result_documents' => ['nullable', 'array'],
            'result_documents.*' => ['file', 'max:10240'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'data' => $validator->errors(),
            ], 422);
        }

        try {
            $sample = DB::transaction(function () use ($request) {
                $project = Project::findOrFail((int) $request->input('project_id'));

                $sampleCode = $this->generateSampleCode();
                $projectName = $this->getProjectDisplayName($project);

                $sample = SampleRecord::create([
                    'project_id' => $project->id,
                    'study_area_id' => $request->input('study_area_id'),
                    'collector_user_id' => auth()->id(),
                    'created_by' => auth()->id(),

                    'sample_code' => $sampleCode,
                    'sample_name' => $request->input('sample_name') ?: $sampleCode,
                    'project_name' => $projectName,
                    'study_area_name' => $request->input('study_area_name'),
                    'sample_type' => $request->input('sample_type'),
                    'material' => $request->input('material'),

                    'collection_location' => $request->input('collection_location'),
                    'district' => $request->input('district'),
                    'sector' => $request->input('sector'),
                    'latitude' => $request->input('latitude'),
                    'longitude' => $request->input('longitude'),

                    'depth' => $request->input('depth'),
                    'collected_by' => $request->input('collected_by'),
                    'collected_date' => $request->input('collected_date'),
                    'chain_of_custody' => $request->input('chain_of_custody'),
                    'status' => $request->input('status', 'collected'),
                    'notes' => $request->input('notes'),
                    'metadata' => [
                        'google_map_location' => $request->input('google_map_location'),
                        'generated_sample_code' => true,
                        'sample_code_generated_at' => now()->toDateTimeString(),
                    ],
                ]);

                if ($this->hasLaboratoryPayload($request)) {
                    $result = LaboratoryResult::create([
                        'sample_record_id' => $sample->id,
                        'laboratory' => $request->input('laboratory'),
                        'lab_reference' => $this->generateLabReference(),
                        'received_date' => $request->input('received_date'),
                        'test_type' => $request->input('test_type'),
                        'test_method' => $request->input('test_method'),
                        'tested_by' => $request->input('tested_by'),
                        'test_date' => $request->input('test_date'),
                        'result_status' => $request->input('result_status', 'pending'),
                        'test_results' => $request->input('test_results'),
                        'result_summary' => $request->input('result_summary'),
                        'interpretation' => $request->input('interpretation'),
                    ]);

                    $this->storeResultDocuments($request, $sample, $result);
                }

                return $sample;
            });

            $sample->load([
                'project',
                'studyArea',
                'collector',
                'latestLaboratoryResult',
                'resultDocuments',
            ])->loadCount('resultDocuments');

            return response()->json([
                'success' => true,
                'message' => 'Sample and laboratory result saved successfully.',
                'data' => $sample,
            ], 201);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'Server error while saving sample.',
                'error' => config('app.debug') ? $exception->getMessage() : null,
            ], 500);
        }
    }

    public function show(string|int $id): JsonResponse
    {
        $sample = SampleRecord::query()
            ->with([
                'project',
                'studyArea',
                'collector',
                'latestLaboratoryResult',
                'laboratoryResults',
                'resultDocuments',
            ])
            ->withCount('resultDocuments')
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'message' => 'Sample loaded successfully.',
            'data' => $sample,
        ]);
    }

    public function update(Request $request, string|int $id): JsonResponse
    {
        $sample = SampleRecord::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],

            'sample_name' => ['nullable', 'string', 'max:255'],
            'study_area_id' => ['nullable', 'integer'],
            'study_area_name' => ['nullable', 'string', 'max:255'],
            'sample_type' => ['nullable', 'string', 'max:150'],
            'material' => ['nullable', 'string', 'max:150'],

            'collection_location' => ['nullable', 'string', 'max:1000'],
            'google_map_location' => ['nullable', 'string', 'max:1000'],
            'district' => ['nullable', 'string', 'max:150'],
            'sector' => ['nullable', 'string', 'max:150'],

            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],

            'depth' => ['nullable', 'string', 'max:100'],
            'collected_by' => ['nullable', 'string', 'max:255'],
            'collected_date' => ['nullable', 'date'],
            'chain_of_custody' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string'],

            'laboratory' => ['nullable', 'string', 'max:255'],
            'received_date' => ['nullable', 'date'],
            'test_type' => ['nullable', 'string', 'max:150'],
            'test_method' => ['nullable', 'string', 'max:150'],
            'tested_by' => ['nullable', 'string', 'max:255'],
            'test_date' => ['nullable', 'date'],
            'result_status' => ['nullable', 'string', 'max:50'],
            'result_summary' => ['nullable', 'string'],
            'interpretation' => ['nullable', 'string'],
            'test_results' => ['nullable', 'array'],

            'result_documents' => ['nullable', 'array'],
            'result_documents.*' => ['file', 'max:10240'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'data' => $validator->errors(),
            ], 422);
        }

        try {
            DB::transaction(function () use ($request, $sample) {
                $sampleData = $request->only([
                    'study_area_id',
                    'sample_name',
                    'study_area_name',
                    'sample_type',
                    'material',
                    'collection_location',
                    'district',
                    'sector',
                    'latitude',
                    'longitude',
                    'depth',
                    'collected_by',
                    'collected_date',
                    'chain_of_custody',
                    'status',
                    'notes',
                ]);

                if ($request->filled('project_id')) {
                    $project = Project::findOrFail((int) $request->input('project_id'));

                    $sampleData['project_id'] = $project->id;
                    $sampleData['project_name'] = $this->getProjectDisplayName($project);
                }

                if ($request->filled('google_map_location')) {
                    $metadata = $sample->metadata ?? [];
                    if (!is_array($metadata)) {
                        $metadata = [];
                    }

                    $metadata['google_map_location'] = $request->input('google_map_location');
                    $sampleData['metadata'] = $metadata;
                }

                $sample->update(array_filter(
                    $sampleData,
                    fn ($value) => $value !== null
                ));

                if ($this->hasLaboratoryPayload($request)) {
                    $result = $sample->latestLaboratoryResult()->first();

                    if (!$result) {
                        $result = LaboratoryResult::create([
                            'sample_record_id' => $sample->id,
                            'lab_reference' => $this->generateLabReference(),
                            'result_status' => $request->input('result_status', 'pending'),
                        ]);
                    }

                    $result->update(array_filter([
                        'laboratory' => $request->input('laboratory'),
                        'received_date' => $request->input('received_date'),
                        'test_type' => $request->input('test_type'),
                        'test_method' => $request->input('test_method'),
                        'tested_by' => $request->input('tested_by'),
                        'test_date' => $request->input('test_date'),
                        'result_status' => $request->input('result_status'),
                        'test_results' => $request->input('test_results'),
                        'result_summary' => $request->input('result_summary'),
                        'interpretation' => $request->input('interpretation'),
                    ], fn ($value) => $value !== null));

                    $this->storeResultDocuments($request, $sample, $result);
                }
            });

            $sample->load([
                'project',
                'studyArea',
                'collector',
                'latestLaboratoryResult',
                'resultDocuments',
            ])->loadCount('resultDocuments');

            return response()->json([
                'success' => true,
                'message' => 'Sample updated successfully.',
                'data' => $sample,
            ]);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'Server error while updating sample.',
                'error' => config('app.debug') ? $exception->getMessage() : null,
            ], 500);
        }
    }

    public function destroy(string|int $id): JsonResponse
    {
        $sample = SampleRecord::findOrFail($id);
        $sample->delete();

        return response()->json([
            'success' => true,
            'message' => 'Sample deleted successfully.',
        ]);
    }

    public function restore(string|int $id): JsonResponse
    {
        $sample = SampleRecord::withTrashed()->findOrFail($id);
        $sample->restore();

        $sample->load([
            'project',
            'studyArea',
            'collector',
            'latestLaboratoryResult',
            'resultDocuments',
        ])->loadCount('resultDocuments');

        return response()->json([
            'success' => true,
            'message' => 'Sample restored successfully.',
            'data' => $sample,
        ]);
    }

    public function storeLaboratoryResult(Request $request, string|int $sampleId): JsonResponse
    {
        $sample = SampleRecord::findOrFail($sampleId);

        $validator = Validator::make($request->all(), [
            'laboratory' => ['nullable', 'string', 'max:255'],
            'received_date' => ['nullable', 'date'],
            'test_type' => ['nullable', 'string', 'max:150'],
            'test_method' => ['nullable', 'string', 'max:150'],
            'tested_by' => ['nullable', 'string', 'max:255'],
            'test_date' => ['nullable', 'date'],
            'result_status' => ['nullable', 'string', 'max:50'],
            'result_summary' => ['nullable', 'string'],
            'interpretation' => ['nullable', 'string'],
            'test_results' => ['nullable', 'array'],
            'result_documents' => ['nullable', 'array'],
            'result_documents.*' => ['file', 'max:10240'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'data' => $validator->errors(),
            ], 422);
        }

        try {
            $result = DB::transaction(function () use ($request, $sample) {
                $result = LaboratoryResult::create([
                    'sample_record_id' => $sample->id,
                    'laboratory' => $request->input('laboratory'),
                    'lab_reference' => $this->generateLabReference(),
                    'received_date' => $request->input('received_date'),
                    'test_type' => $request->input('test_type'),
                    'test_method' => $request->input('test_method'),
                    'tested_by' => $request->input('tested_by'),
                    'test_date' => $request->input('test_date'),
                    'result_status' => $request->input('result_status', 'pending'),
                    'test_results' => $request->input('test_results'),
                    'result_summary' => $request->input('result_summary'),
                    'interpretation' => $request->input('interpretation'),
                ]);

                $this->storeResultDocuments($request, $sample, $result);

                return $result;
            });

            $result->load('documents');

            return response()->json([
                'success' => true,
                'message' => 'Laboratory result saved successfully.',
                'data' => $result,
            ], 201);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'Server error while saving laboratory result.',
                'error' => config('app.debug') ? $exception->getMessage() : null,
            ], 500);
        }
    }

    public function updateLaboratoryResult(
        Request $request,
        string|int $sampleId,
        string|int $resultId
    ): JsonResponse {
        $sample = SampleRecord::findOrFail($sampleId);

        $result = LaboratoryResult::where('sample_record_id', $sample->id)
            ->where('id', $resultId)
            ->firstOrFail();

        $validator = Validator::make($request->all(), [
            'laboratory' => ['nullable', 'string', 'max:255'],
            'received_date' => ['nullable', 'date'],
            'test_type' => ['nullable', 'string', 'max:150'],
            'test_method' => ['nullable', 'string', 'max:150'],
            'tested_by' => ['nullable', 'string', 'max:255'],
            'test_date' => ['nullable', 'date'],
            'result_status' => ['nullable', 'string', 'max:50'],
            'result_summary' => ['nullable', 'string'],
            'interpretation' => ['nullable', 'string'],
            'test_results' => ['nullable', 'array'],
            'result_documents' => ['nullable', 'array'],
            'result_documents.*' => ['file', 'max:10240'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'data' => $validator->errors(),
            ], 422);
        }

        try {
            DB::transaction(function () use ($request, $sample, $result) {
                $result->update(array_filter([
                    'laboratory' => $request->input('laboratory'),
                    'received_date' => $request->input('received_date'),
                    'test_type' => $request->input('test_type'),
                    'test_method' => $request->input('test_method'),
                    'tested_by' => $request->input('tested_by'),
                    'test_date' => $request->input('test_date'),
                    'result_status' => $request->input('result_status'),
                    'test_results' => $request->input('test_results'),
                    'result_summary' => $request->input('result_summary'),
                    'interpretation' => $request->input('interpretation'),
                ], fn ($value) => $value !== null));

                $this->storeResultDocuments($request, $sample, $result);
            });

            $result->load('documents');

            return response()->json([
                'success' => true,
                'message' => 'Laboratory result updated successfully.',
                'data' => $result,
            ]);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'Server error while updating laboratory result.',
                'error' => config('app.debug') ? $exception->getMessage() : null,
            ], 500);
        }
    }

    public function deleteLaboratoryResult(
        string|int $sampleId,
        string|int $resultId
    ): JsonResponse {
        $sample = SampleRecord::findOrFail($sampleId);

        $result = LaboratoryResult::where('sample_record_id', $sample->id)
            ->where('id', $resultId)
            ->firstOrFail();

        foreach ($result->documents as $document) {
            $this->deleteDocumentFile($document);
            $document->delete();
        }

        $result->delete();

        return response()->json([
            'success' => true,
            'message' => 'Laboratory result deleted successfully.',
        ]);
    }

    public function deleteResultDocument(
        string|int $sampleId,
        string|int $resultId,
        string|int $documentId
    ): JsonResponse {
        $sample = SampleRecord::findOrFail($sampleId);

        $result = LaboratoryResult::where('sample_record_id', $sample->id)
            ->where('id', $resultId)
            ->firstOrFail();

        $document = LaboratoryResultDocument::where('sample_record_id', $sample->id)
            ->where('laboratory_result_id', $result->id)
            ->where('id', $documentId)
            ->firstOrFail();

        $this->deleteDocumentFile($document);
        $document->delete();

        return response()->json([
            'success' => true,
            'message' => 'Result document deleted successfully.',
        ]);
    }

    private function hasLaboratoryPayload(Request $request): bool
    {
        return $request->filled('laboratory')
            || $request->filled('received_date')
            || $request->filled('test_type')
            || $request->filled('test_method')
            || $request->filled('tested_by')
            || $request->filled('test_date')
            || $request->filled('result_status')
            || $request->filled('result_summary')
            || $request->filled('interpretation')
            || $request->has('test_results')
            || $request->hasFile('result_documents');
    }

    private function storeResultDocuments(
        Request $request,
        SampleRecord $sample,
        LaboratoryResult $result
    ): void {
        if (!$request->hasFile('result_documents')) {
            return;
        }

        foreach ($request->file('result_documents') as $file) {
            $path = $file->store('laboratory-results', 'public');

            $documentData = [
                'sample_record_id' => $sample->id,
                'laboratory_result_id' => $result->id,
                'document_type' => 'result_report',
                'title' => pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
                'original_file_name' => $file->getClientOriginalName(),
                'stored_file_name' => basename($path),
                'file_path' => $path,
                'file_size' => $file->getSize(),
                'mime_type' => $file->getMimeType(),
            ];

            if (Schema::hasColumn('laboratory_result_documents', 'uploaded_by')) {
                $documentData['uploaded_by'] = auth()->id();
            }

            LaboratoryResultDocument::create($documentData);
        }
    }

    private function deleteDocumentFile(LaboratoryResultDocument $document): void
    {
        if ($document->file_path && Storage::disk('public')->exists($document->file_path)) {
            Storage::disk('public')->delete($document->file_path);
        }
    }

    private function generateSampleCode(): string
    {
        $date = now()->format('Ymd');
        $counter = SampleRecord::whereDate('created_at', now()->toDateString())->count() + 1;

        do {
            $code = 'SMP-' . $date . '-' . str_pad((string) $counter, 4, '0', STR_PAD_LEFT);
            $exists = SampleRecord::where('sample_code', $code)->exists();
            $counter++;
        } while ($exists);

        return $code;
    }

    private function generateLabReference(): string
    {
        $date = now()->format('Ymd');
        $counter = LaboratoryResult::whereDate('created_at', now()->toDateString())->count() + 1;

        do {
            $reference = 'LAB-' . $date . '-' . str_pad((string) $counter, 4, '0', STR_PAD_LEFT);
            $exists = LaboratoryResult::where('lab_reference', $reference)->exists();
            $counter++;
        } while ($exists);

        return $reference;
    }

    private function getProjectDisplayName(Project $project): string
    {
        foreach (['name', 'title', 'project_name', 'code'] as $field) {
            $value = $project->getAttribute($field);

            if ($value) {
                return (string) $value;
            }
        }

        return 'Project #' . $project->id;
    }
}