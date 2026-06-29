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
use Illuminate\Support\Facades\Validator;

class SampleLaboratoryController extends Controller
{
    public function projects(): JsonResponse
    {
        $projects = Project::query()
            ->orderByDesc('id')
            ->get()
            ->map(function (Project $project) {
                $name = $project->name
                    ?? $project->title
                    ?? $project->project_name
                    ?? $project->projectName
                    ?? ('Project #' . $project->id);

                $code = $project->code
                    ?? $project->project_code
                    ?? $project->projectCode
                    ?? null;

                $studyArea = $project->study_area_name
                    ?? $project->studyArea?->name
                    ?? $project->studyArea?->title
                    ?? null;

                return [
                    'id' => $project->id,
                    'name' => $name,
                    'code' => $code,
                    'study_area_name' => $studyArea,
                ];
            })
            ->values();

        return response()->json([
            'success' => true,
            'message' => 'Projects loaded successfully.',
            'data' => $projects,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->query('per_page', 20);
        $perPage = $perPage > 0 ? min($perPage, 200) : 20;

        $query = SampleRecord::query()
            ->with([
                'project',
                'collector',
                'latestLaboratoryResult.documents',
                'resultDocuments',
            ])
            ->withCount('resultDocuments')
            ->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
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
            'study_area_name' => ['nullable', 'string', 'max:255'],
            'sample_type' => ['required', 'string', 'max:150'],
            'material' => ['nullable', 'string', 'max:150'],
            'collection_location' => ['required', 'string', 'max:1000'],
            'district' => ['nullable', 'string', 'max:150'],
            'sector' => ['nullable', 'string', 'max:150'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
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

        $sample = DB::transaction(function () use ($request) {
            $project = Project::query()->findOrFail($request->integer('project_id'));

            $projectName = $project->name
                ?? $project->title
                ?? $project->project_name
                ?? ('Project #' . $project->id);

            $studyAreaName = $request->input('study_area_name')
                ?: ($project->study_area_name ?? $project->studyArea?->name ?? null);

            $sample = SampleRecord::create([
                'project_id' => $project->id,
                'created_by' => auth()->id(),
                'sample_code' => $this->generateSampleCode(),
                'sample_name' => $request->input('sample_name') ?: null,
                'project_name' => $projectName,
                'study_area_name' => $studyAreaName,
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
            ]);

            $hasLabData = $request->filled('laboratory')
                || $request->filled('received_date')
                || $request->filled('test_type')
                || $request->filled('test_method')
                || $request->filled('tested_by')
                || $request->filled('test_date')
                || $request->filled('result_summary')
                || $request->filled('interpretation')
                || $request->has('test_results')
                || $request->hasFile('result_documents');

            if ($hasLabData) {
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

                if ($request->hasFile('result_documents')) {
                    foreach ($request->file('result_documents') as $file) {
                        $path = $file->store('laboratory-results', 'public');

                        LaboratoryResultDocument::create([
                            'sample_record_id' => $sample->id,
                            'laboratory_result_id' => $result->id,
                            'document_type' => 'result_report',
                            'title' => pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
                            'original_file_name' => $file->getClientOriginalName(),
                            'file_path' => $path,
                            'file_size' => $file->getSize(),
                            'mime_type' => $file->getMimeType(),
                        ]);
                    }
                }
            }

            return $sample;
        });

        $sample->load([
            'project',
            'collector',
            'latestLaboratoryResult.documents',
            'resultDocuments',
        ])->loadCount('resultDocuments');

        return response()->json([
            'success' => true,
            'message' => 'Sample and laboratory result saved successfully.',
            'data' => $sample,
        ], 201);
    }

    private function generateSampleCode(): string
    {
        $prefix = 'SMP-' . now()->format('Ymd');
        $next = 1;

        $lastCode = SampleRecord::withTrashed()
            ->where('sample_code', 'like', $prefix . '-%')
            ->orderByDesc('id')
            ->value('sample_code');

        if ($lastCode && preg_match('/-(\d+)$/', $lastCode, $matches)) {
            $next = ((int) $matches[1]) + 1;
        }

        do {
            $code = $prefix . '-' . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
            $next++;
        } while (SampleRecord::withTrashed()->where('sample_code', $code)->exists());

        return $code;
    }

    private function generateLabReference(): string
    {
        $prefix = 'LAB-' . now()->format('Ymd');
        $next = 1;

        $lastReference = LaboratoryResult::query()
            ->where('lab_reference', 'like', $prefix . '-%')
            ->orderByDesc('id')
            ->value('lab_reference');

        if ($lastReference && preg_match('/-(\d+)$/', $lastReference, $matches)) {
            $next = ((int) $matches[1]) + 1;
        }

        do {
            $reference = $prefix . '-' . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
            $next++;
        } while (LaboratoryResult::where('lab_reference', $reference)->exists());

        return $reference;
    }
}