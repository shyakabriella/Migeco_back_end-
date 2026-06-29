<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\LaboratoryResult;
use App\Models\LaboratoryResultDocument;
use App\Models\SampleRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
                'collector:id,name,email',
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
            'sample_code' => ['required', 'string', 'max:100'],
            'sample_name' => ['nullable', 'string', 'max:255'],
            'project_name' => ['nullable', 'string', 'max:255'],
            'study_area_name' => ['nullable', 'string', 'max:255'],
            'sample_type' => ['required', 'string', 'max:150'],
            'material' => ['nullable', 'string', 'max:150'],
            'collection_location' => ['nullable', 'string', 'max:255'],
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
            'lab_reference' => ['nullable', 'string', 'max:150'],
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
            $sample = SampleRecord::create([
                'sample_code' => $request->input('sample_code'),
                'sample_name' => $request->input('sample_name', $request->input('sample_code')),
                'project_name' => $request->input('project_name'),
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
            ]);

            $hasLabData = $request->filled('laboratory')
                || $request->filled('lab_reference')
                || $request->filled('test_type')
                || $request->filled('test_method')
                || $request->filled('tested_by')
                || $request->filled('result_summary')
                || $request->filled('interpretation')
                || $request->has('test_results')
                || $request->hasFile('result_documents');

            if ($hasLabData) {
                $result = LaboratoryResult::create([
                    'sample_record_id' => $sample->id,
                    'laboratory' => $request->input('laboratory'),
                    'lab_reference' => $request->input('lab_reference'),
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
            'collector:id,name,email',
            'latestLaboratoryResult.documents',
            'resultDocuments',
        ])->loadCount('resultDocuments');

        return response()->json([
            'success' => true,
            'message' => 'Sample and laboratory result saved successfully.',
            'data' => $sample,
        ], 201);
    }
}