<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController as BaseController;
use App\Models\Document;
use App\Models\DocumentSandboxLog;
use App\Services\DocumentSandboxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DocumentSandboxController extends BaseController
{
    /**
     * Users who can manage sandbox.
     */
    private function canManageSandbox($user): bool
    {
        return $user && in_array($user->role?->slug, [
            'admin',
            'security_officer',
            'document_controller',
            'project_manager',
        ]);
    }

    /**
     * Test one document in sandbox.
     */
    public function testDocument(
        Request $request,
        string $id,
        DocumentSandboxService $sandboxService
    ): JsonResponse {
        $user = $request->user();

        if (!$user) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.',
            ], 401);
        }

        if (!$this->canManageSandbox($user)) {
            return $this->sendError('Permission Denied.', [
                'error' => 'Only Admin, Security Officer, Document Controller, or Project Manager can run sandbox tests.',
            ], 403);
        }

        $document = Document::find($id);

        if (!$document) {
            return $this->sendError('Not Found.', [
                'error' => 'Document not found.',
            ], 404);
        }

        $result = $sandboxService->test($document, $user, 'manual');

        $document->refresh()->load([
            'project:id,name,code,status,security_level',
            'category:id,name,slug',
            'uploader:id,name,email',
            'sandboxLogs' => function ($query) {
                $query->latest()->limit(5);
            },
        ]);

        if (($result['status'] ?? null) === 'failed') {
            return $this->sendError('Sandbox Test Failed.', [
                'result' => $result,
                'document' => $document,
            ], 500);
        }

        return $this->sendResponse([
            'result' => $result,
            'document' => $document,
        ], 'Sandbox test completed.');
    }

    /**
     * Batch test clean documents that are not yet sandbox tested.
     */
    public function testPendingDocuments(
        Request $request,
        DocumentSandboxService $sandboxService
    ): JsonResponse {
        $user = $request->user();

        if (!$user) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.',
            ], 401);
        }

        if (!$this->canManageSandbox($user)) {
            return $this->sendError('Permission Denied.', [
                'error' => 'Only Admin, Security Officer, Document Controller, or Project Manager can batch test documents.',
            ], 403);
        }

        $documents = Document::where('status', 'active')
            ->where('scan_status', 'clean')
            ->whereIn('sandbox_status', [
                'not_tested',
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
                'result' => $sandboxService->test($document, $user, 'batch'),
            ];
        }

        return $this->sendResponse([
            'total_processed' => count($results),
            'results' => $results,
        ], 'Pending sandbox documents tested successfully.');
    }

    /**
     * List unsafe sandbox documents.
     */
    public function unsafeDocuments(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.',
            ], 401);
        }

        if (!$this->canManageSandbox($user)) {
            return $this->sendError('Permission Denied.', [
                'error' => 'Only Admin, Security Officer, Document Controller, or Project Manager can view unsafe sandbox documents.',
            ], 403);
        }

        $documents = Document::with([
                'project:id,name,code',
                'category:id,name,slug',
                'uploader:id,name,email',
                'sandboxTester:id,name,email',
            ])
            ->where('sandbox_status', 'unsafe')
            ->latest()
            ->get();

        return $this->sendResponse($documents, 'Unsafe sandbox documents retrieved successfully.');
    }

    /**
     * Mark unsafe document as rejected.
     */
    public function rejectUnsafeDocument(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.',
            ], 401);
        }

        if (!$this->canManageSandbox($user)) {
            return $this->sendError('Permission Denied.', [
                'error' => 'Only Admin, Security Officer, Document Controller, or Project Manager can reject unsafe documents.',
            ], 403);
        }

        $document = Document::where('sandbox_status', 'unsafe')->find($id);

        if (!$document) {
            return $this->sendError('Not Found.', [
                'error' => 'Unsafe document not found.',
            ], 404);
        }

        $document->update([
            'status' => 'rejected',
            'sandbox_message' => 'Document rejected after sandbox review by ' . $user->name,
        ]);

        return $this->sendResponse($document, 'Unsafe document rejected successfully.');
    }

    /**
     * Sandbox logs.
     */
    public function sandboxLogs(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.',
            ], 401);
        }

        if (!$this->canManageSandbox($user)) {
            return $this->sendError('Permission Denied.', [
                'error' => 'Only Admin, Security Officer, Document Controller, or Project Manager can view sandbox logs.',
            ], 403);
        }

        $logs = DocumentSandboxLog::with([
                'document:id,document_code,title,status,scan_status,sandbox_status',
                'tester:id,name,email',
            ])
            ->when($request->filled('document_id'), function ($query) use ($request) {
                $query->where('document_id', $request->document_id);
            })
            ->when($request->filled('status'), function ($query) use ($request) {
                $query->where('status', $request->status);
            })
            ->latest()
            ->get();

        return $this->sendResponse($logs, 'Sandbox logs retrieved successfully.');
    }

    /**
     * Sandbox summary.
     */
    public function sandboxSummary(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.',
            ], 401);
        }

        if (!$this->canManageSandbox($user)) {
            return $this->sendError('Permission Denied.', [
                'error' => 'Only Admin, Security Officer, Document Controller, or Project Manager can view sandbox summary.',
            ], 403);
        }

        $summary = [
            'total_documents' => Document::count(),
            'clean_active_documents' => Document::where('status', 'active')
                ->where('scan_status', 'clean')
                ->count(),
            'not_tested_documents' => Document::where('sandbox_status', 'not_tested')->count(),
            'pending_documents' => Document::where('sandbox_status', 'pending')->count(),
            'safe_documents' => Document::where('sandbox_status', 'safe')->count(),
            'unsafe_documents' => Document::where('sandbox_status', 'unsafe')->count(),
            'failed_documents' => Document::where('sandbox_status', 'failed')->count(),
        ];

        return $this->sendResponse($summary, 'Sandbox summary retrieved successfully.');
    }
}