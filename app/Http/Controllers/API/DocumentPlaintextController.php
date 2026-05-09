<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController as BaseController;
use App\Models\Document;
use App\Models\DocumentPlaintext;
use App\Models\DocumentPlaintextLog;
use App\Services\DocumentPlaintextExtractor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DocumentPlaintextController extends BaseController
{
    /**
     * Users who can manage plaintext extraction.
     */
    private function canManagePlaintext($user): bool
    {
        return $user && in_array($user->role?->slug, [
            'admin',
            'document_controller',
            'security_officer',
            'project_manager',
        ]);
    }

    /**
     * Users who can view plaintext.
     */
    private function canViewPlaintext($user): bool
    {
        return $user && (
            $user->isAdmin()
            || $user->hasPermission('view_documents')
            || in_array($user->role?->slug, [
                'auditor',
                'document_controller',
                'project_manager',
                'security_officer',
                'geologist',
                'engineer',
            ])
        );
    }

    /**
     * Extract plaintext from one document.
     */
    public function extractDocument(
        Request $request,
        string $id,
        DocumentPlaintextExtractor $extractor
    ): JsonResponse {
        $user = $request->user();

        if (!$user) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.',
            ], 401);
        }

        if (!$this->canManagePlaintext($user)) {
            return $this->sendError('Permission Denied.', [
                'error' => 'Only Admin, Document Controller, Security Officer, or Project Manager can extract plaintext.',
            ], 403);
        }

        $document = Document::find($id);

        if (!$document) {
            return $this->sendError('Not Found.', [
                'error' => 'Document not found.',
            ], 404);
        }

        $result = $extractor->extract($document, $user, 'manual');

        $document->refresh()->load([
            'project:id,name,code,status,security_level',
            'category:id,name,slug',
            'uploader:id,name,email',
            'plaintext',
            'plaintextLogs' => function ($query) {
                $query->latest()->limit(5);
            },
        ]);

        if (($result['status'] ?? null) !== 'extracted') {
            return $this->sendError('Plaintext Extraction Failed.', [
                'result' => $result,
                'document' => $document,
            ], 500);
        }

        return $this->sendResponse([
            'result' => $result,
            'document' => $document,
        ], 'Plaintext extracted successfully.');
    }

    /**
     * Extract plaintext from all clean documents.
     */
    public function extractPendingDocuments(
        Request $request,
        DocumentPlaintextExtractor $extractor
    ): JsonResponse {
        $user = $request->user();

        if (!$user) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.',
            ], 401);
        }

        if (!$this->canManagePlaintext($user)) {
            return $this->sendError('Permission Denied.', [
                'error' => 'Only Admin, Document Controller, Security Officer, or Project Manager can batch extract plaintext.',
            ], 403);
        }

        $documents = Document::where('status', 'active')
            ->where('scan_status', 'clean')
            ->whereIn('plaintext_status', [
                'not_extracted',
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
                'result' => $extractor->extract($document, $user, 'batch'),
            ];
        }

        return $this->sendResponse([
            'total_processed' => count($results),
            'results' => $results,
        ], 'Plaintext batch extraction completed.');
    }

    /**
     * View extracted plaintext for a document.
     */
    public function showPlaintext(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.',
            ], 401);
        }

        if (!$this->canViewPlaintext($user)) {
            return $this->sendError('Permission Denied.', [
                'error' => 'You are not allowed to view extracted plaintext.',
            ], 403);
        }

        $document = Document::with([
            'project:id,name,code,status,security_level',
            'category:id,name,slug',
            'plaintext',
        ])->find($id);

        if (!$document) {
            return $this->sendError('Not Found.', [
                'error' => 'Document not found.',
            ], 404);
        }

        if (!$document->plaintext) {
            return $this->sendError('Not Found.', [
                'error' => 'Plaintext has not been extracted for this document.',
            ], 404);
        }

        return $this->sendResponse([
            'document' => [
                'id' => $document->id,
                'document_code' => $document->document_code,
                'title' => $document->title,
                'plaintext_status' => $document->plaintext_status,
            ],
            'plaintext' => $document->plaintext,
        ], 'Plaintext retrieved successfully.');
    }

    /**
     * Search inside extracted plaintext.
     */
    public function searchPlaintext(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.',
            ], 401);
        }

        if (!$this->canViewPlaintext($user)) {
            return $this->sendError('Permission Denied.', [
                'error' => 'You are not allowed to search extracted plaintext.',
            ], 403);
        }

        $request->validate([
            'q' => 'required|string|min:2|max:255',
        ]);

        $query = $request->q;

        $results = DocumentPlaintext::with([
                'document:id,document_code,title,security_level,status,scan_status,plaintext_status',
            ])
            ->where('content', 'LIKE', '%' . $query . '%')
            ->latest()
            ->limit(50)
            ->get();

        return $this->sendResponse([
            'query' => $query,
            'total' => $results->count(),
            'results' => $results,
        ], 'Plaintext search completed successfully.');
    }

    /**
     * Plaintext extraction logs.
     */
    public function plaintextLogs(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.',
            ], 401);
        }

        if (!$this->canManagePlaintext($user)) {
            return $this->sendError('Permission Denied.', [
                'error' => 'Only Admin, Document Controller, Security Officer, or Project Manager can view plaintext logs.',
            ], 403);
        }

        $logs = DocumentPlaintextLog::with([
                'document:id,document_code,title,plaintext_status,status,scan_status',
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

        return $this->sendResponse($logs, 'Plaintext logs retrieved successfully.');
    }

    /**
     * Plaintext dashboard summary.
     */
    public function plaintextSummary(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.',
            ], 401);
        }

        if (!$this->canManagePlaintext($user)) {
            return $this->sendError('Permission Denied.', [
                'error' => 'Only Admin, Document Controller, Security Officer, or Project Manager can view plaintext summary.',
            ], 403);
        }

        $summary = [
            'total_documents' => Document::count(),
            'clean_active_documents' => Document::where('status', 'active')
                ->where('scan_status', 'clean')
                ->count(),
            'plaintext_extracted_documents' => Document::where('plaintext_status', 'extracted')->count(),
            'not_extracted_documents' => Document::where('plaintext_status', 'not_extracted')->count(),
            'failed_plaintext_documents' => Document::where('plaintext_status', 'failed')->count(),
            'pending_plaintext_documents' => Document::where('plaintext_status', 'pending')->count(),
            'total_plaintext_records' => DocumentPlaintext::count(),
        ];

        return $this->sendResponse($summary, 'Plaintext summary retrieved successfully.');
    }
}