<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController as BaseController;
use App\Models\Document;
use App\Models\DocumentAiAnalysis;
use App\Models\DocumentAiAnalysisLog;
use App\Services\DocumentAiAnalyzer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class DocumentAiController extends BaseController
{
    /**
     * Users who can manage AI.
     */
    private function canManageAi($user): bool
    {
        return $user && (
            $user->isAdmin()
            || $user->hasPermission('manage_ai')
            || in_array($user->role?->slug, [
                'admin',
                'document_controller',
                'security_officer',
                'project_manager',
            ])
        );
    }

    /**
     * Users who can view/use AI.
     */
    private function canUseAi($user): bool
    {
        return $user && (
            $user->isAdmin()
            || $user->hasPermission('use_ai')
            || in_array($user->role?->slug, [
                'geologist',
                'engineer',
                'project_manager',
                'document_controller',
                'security_officer',
                'auditor',
            ])
        );
    }

    /**
     * Analyze one document.
     */
    public function analyzeDocument(
        Request $request,
        string $id,
        DocumentAiAnalyzer $analyzer
    ): JsonResponse {
        $user = $request->user();

        if (!$user) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.',
            ], 401);
        }

        if (!$this->canManageAi($user)) {
            return $this->sendError('Permission Denied.', [
                'error' => 'Only Admin, Security Officer, Project Manager, or Document Controller can analyze documents using AI.',
            ], 403);
        }

        $document = Document::with('plaintext')->find($id);

        if (!$document) {
            return $this->sendError('Not Found.', [
                'error' => 'Document not found.',
            ], 404);
        }

        $result = $analyzer->analyze($document, $user, 'manual');

        $document->refresh()->load([
            'project:id,name,code,status,security_level',
            'category:id,name,slug',
            'uploader:id,name,email',
            'plaintext',
            'aiAnalysis',
            'aiSuggestedCategory:id,name,slug',
            'aiAnalysisLogs' => function ($query) {
                $query->latest()->limit(5);
            },
        ]);

        if (($result['status'] ?? null) !== 'analyzed') {
            return $this->sendError('AI Analysis Failed.', [
                'result' => $result,
                'document' => $document,
            ], 500);
        }

        return $this->sendResponse([
            'result' => $result,
            'document' => $document,
        ], 'AI document analysis completed successfully.');
    }

    /**
     * Analyze all pending AI documents.
     */
    public function analyzePendingDocuments(
        Request $request,
        DocumentAiAnalyzer $analyzer
    ): JsonResponse {
        $user = $request->user();

        if (!$user) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.',
            ], 401);
        }

        if (!$this->canManageAi($user)) {
            return $this->sendError('Permission Denied.', [
                'error' => 'Only Admin, Security Officer, Project Manager, or Document Controller can batch analyze documents.',
            ], 403);
        }

        $documents = Document::where('status', 'active')
            ->where('scan_status', 'clean')
            ->where('sandbox_status', 'safe')
            ->where('plaintext_status', 'extracted')
            ->whereIn('ai_status', [
                'not_analyzed',
                'failed',
            ])
            ->latest()
            ->limit(20)
            ->get();

        $results = [];

        foreach ($documents as $document) {
            $results[] = [
                'document_id' => $document->id,
                'document_code' => $document->document_code,
                'title' => $document->title,
                'result' => $analyzer->analyze($document, $user, 'batch'),
            ];
        }

        return $this->sendResponse([
            'total_processed' => count($results),
            'results' => $results,
        ], 'Pending AI document analysis completed.');
    }

    /**
     * Show AI analysis for one document.
     */
    public function showAnalysis(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.',
            ], 401);
        }

        if (!$this->canUseAi($user)) {
            return $this->sendError('Permission Denied.', [
                'error' => 'You are not allowed to view AI analysis.',
            ], 403);
        }

        $document = Document::with([
            'project:id,name,code,status,security_level',
            'category:id,name,slug',
            'aiAnalysis',
            'aiSuggestedCategory:id,name,slug',
        ])->find($id);

        if (!$document) {
            return $this->sendError('Not Found.', [
                'error' => 'Document not found.',
            ], 404);
        }

        if (!$document->aiAnalysis) {
            return $this->sendError('Not Found.', [
                'error' => 'AI analysis has not been completed for this document.',
            ], 404);
        }

        return $this->sendResponse([
            'document' => [
                'id' => $document->id,
                'document_code' => $document->document_code,
                'title' => $document->title,
                'ai_status' => $document->ai_status,
            ],
            'analysis' => $document->aiAnalysis,
        ], 'AI analysis retrieved successfully.');
    }

    /**
     * Search AI analysis records.
     */
    public function searchAnalysis(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.',
            ], 401);
        }

        if (!$this->canUseAi($user)) {
            return $this->sendError('Permission Denied.', [
                'error' => 'You are not allowed to search AI analysis.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'q' => [
                'required',
                'string',
                'min:2',
                'max:255',
            ],
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors(), 422);
        }

        $query = $request->q;

        $results = DocumentAiAnalysis::with([
                'document:id,document_code,title,status,scan_status,sandbox_status,ai_status,security_level',
                'suggestedCategory:id,name,slug',
            ])
            ->where(function ($q) use ($query) {
                $q->where('summary', 'LIKE', '%' . $query . '%')
                    ->orWhere('suggested_document_type', 'LIKE', '%' . $query . '%')
                    ->orWhere('detected_language', 'LIKE', '%' . $query . '%');
            })
            ->latest()
            ->limit(50)
            ->get();

        return $this->sendResponse([
            'query' => $query,
            'total' => $results->count(),
            'results' => $results,
        ], 'AI analysis search completed successfully.');
    }

    /**
     * Apply AI suggestions to document metadata.
     */
    public function applySuggestions(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.',
            ], 401);
        }

        if (!$this->canManageAi($user)) {
            return $this->sendError('Permission Denied.', [
                'error' => 'Only Admin, Security Officer, Project Manager, or Document Controller can apply AI suggestions.',
            ], 403);
        }

        $document = Document::with('aiAnalysis')->find($id);

        if (!$document) {
            return $this->sendError('Not Found.', [
                'error' => 'Document not found.',
            ], 404);
        }

        if (!$document->aiAnalysis) {
            return $this->sendError('No AI Analysis.', [
                'error' => 'Run AI analysis before applying suggestions.',
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'apply_tags' => ['nullable', 'boolean'],
            'apply_category' => ['nullable', 'boolean'],
            'apply_document_type' => ['nullable', 'boolean'],
            'apply_security_level' => ['nullable', 'boolean'],
            'security_level' => [
                'nullable',
                Rule::in(['public', 'internal', 'confidential', 'restricted']),
            ],
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors(), 422);
        }

        $updates = [];

        if ($request->boolean('apply_tags')) {
            $updates['tags'] = $document->aiAnalysis->suggested_tags ?? [];
        }

        if ($request->boolean('apply_category') && $document->aiAnalysis->suggested_category_id) {
            $updates['document_category_id'] = $document->aiAnalysis->suggested_category_id;
        }

        if ($request->boolean('apply_document_type') && $document->aiAnalysis->suggested_document_type) {
            $updates['document_type'] = $document->aiAnalysis->suggested_document_type;
        }

        if ($request->boolean('apply_security_level')) {
            $updates['security_level'] = $request->security_level
                ?? $document->aiAnalysis->sensitivity_level
                ?? $document->security_level;
        }

        if (empty($updates)) {
            return $this->sendError('No Changes.', [
                'error' => 'No AI suggestion option was selected.',
            ], 422);
        }

        $document->update($updates);

        $document->refresh()->load([
            'category:id,name,slug',
            'aiAnalysis',
        ]);

        return $this->sendResponse($document, 'AI suggestions applied successfully.');
    }

    /**
     * AI analysis logs.
     */
    public function aiLogs(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.',
            ], 401);
        }

        if (!$this->canManageAi($user)) {
            return $this->sendError('Permission Denied.', [
                'error' => 'Only Admin, Security Officer, Project Manager, or Document Controller can view AI logs.',
            ], 403);
        }

        $logs = DocumentAiAnalysisLog::with([
                'document:id,document_code,title,status,scan_status,sandbox_status,plaintext_status,ai_status',
                'performer:id,name,email',
            ])
            ->when($request->filled('document_id'), function ($query) use ($request) {
                $query->where('document_id', $request->document_id);
            })
            ->when($request->filled('status'), function ($query) use ($request) {
                $query->where('status', $request->status);
            })
            ->latest()
            ->get();

        return $this->sendResponse($logs, 'AI analysis logs retrieved successfully.');
    }

    /**
     * AI summary dashboard.
     */
    public function aiSummary(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.',
            ], 401);
        }

        if (!$this->canUseAi($user)) {
            return $this->sendError('Permission Denied.', [
                'error' => 'You are not allowed to view AI summary.',
            ], 403);
        }

        $summary = [
            'total_documents' => Document::count(),
            'ready_for_ai' => Document::where('status', 'active')
                ->where('scan_status', 'clean')
                ->where('sandbox_status', 'safe')
                ->where('plaintext_status', 'extracted')
                ->count(),
            'analyzed_documents' => Document::where('ai_status', 'analyzed')->count(),
            'not_analyzed_documents' => Document::where('ai_status', 'not_analyzed')->count(),
            'pending_ai_documents' => Document::where('ai_status', 'pending')->count(),
            'failed_ai_documents' => Document::where('ai_status', 'failed')->count(),
            'restricted_suggested' => Document::where('ai_sensitivity_level', 'restricted')->count(),
            'confidential_suggested' => Document::where('ai_sensitivity_level', 'confidential')->count(),
        ];

        return $this->sendResponse($summary, 'AI summary retrieved successfully.');
    }
}