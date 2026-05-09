<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController as BaseController;
use App\Models\Document;
use App\Models\DocumentEncryptionLog;
use App\Services\DocumentEncryptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DocumentEncryptionController extends BaseController
{
    /**
     * Users who can manage document cryptography.
     */
    private function canManageCryptography($user): bool
    {
        return $user && in_array($user->role?->slug, [
            'admin',
            'security_officer',
            'document_controller',
        ]);
    }

    /**
     * Encrypt one document.
     */
    public function encryptDocument(
        Request $request,
        string $id,
        DocumentEncryptionService $encryptionService
    ): JsonResponse {
        $user = $request->user();

        if (!$user) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.',
            ], 401);
        }

        if (!$this->canManageCryptography($user)) {
            return $this->sendError('Permission Denied.', [
                'error' => 'Only Admin, Security Officer, or Document Controller can encrypt documents.',
            ], 403);
        }

        $document = Document::find($id);

        if (!$document) {
            return $this->sendError('Not Found.', [
                'error' => 'Document not found.',
            ], 404);
        }

        $result = $encryptionService->encrypt($document, $user);

        $document->refresh()->load([
            'project:id,name,code,status,security_level',
            'category:id,name,slug',
            'uploader:id,name,email',
            'encryptionLogs' => function ($query) {
                $query->latest()->limit(5);
            },
        ]);

        if (($result['status'] ?? null) === 'failed') {
            return $this->sendError('Encryption Failed.', [
                'result' => $result,
                'document' => $document,
            ], 500);
        }

        return $this->sendResponse([
            'result' => $result,
            'document' => $document,
        ], 'Document encryption completed.');
    }

    /**
     * Encrypt all clean documents waiting for encryption.
     */
    public function encryptCleanDocuments(
        Request $request,
        DocumentEncryptionService $encryptionService
    ): JsonResponse {
        $user = $request->user();

        if (!$user) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.',
            ], 401);
        }

        if (!$this->canManageCryptography($user)) {
            return $this->sendError('Permission Denied.', [
                'error' => 'Only Admin, Security Officer, or Document Controller can batch encrypt documents.',
            ], 403);
        }

        $documents = Document::where('status', 'active')
            ->where('scan_status', 'clean')
            ->whereIn('encryption_status', ['not_encrypted', 'failed'])
            ->latest()
            ->limit(20)
            ->get();

        $results = [];

        foreach ($documents as $document) {
            $results[] = [
                'document_id' => $document->id,
                'document_code' => $document->document_code,
                'title' => $document->title,
                'result' => $encryptionService->encrypt($document, $user),
            ];
        }

        return $this->sendResponse([
            'total_processed' => count($results),
            'results' => $results,
        ], 'Clean documents encryption process completed.');
    }

    /**
     * Verify encrypted document.
     */
    public function verifyEncryptedDocument(
        Request $request,
        string $id,
        DocumentEncryptionService $encryptionService
    ): JsonResponse {
        $user = $request->user();

        if (!$user) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.',
            ], 401);
        }

        if (!$this->canManageCryptography($user)) {
            return $this->sendError('Permission Denied.', [
                'error' => 'Only Admin, Security Officer, or Document Controller can verify encrypted documents.',
            ], 403);
        }

        $document = Document::find($id);

        if (!$document) {
            return $this->sendError('Not Found.', [
                'error' => 'Document not found.',
            ], 404);
        }

        if (!$document->isEncrypted()) {
            return $this->sendError('Verification Failed.', [
                'error' => 'Document is not encrypted.',
            ], 422);
        }

        $result = $encryptionService->verify($document, $user);

        if (($result['status'] ?? null) !== 'success') {
            return $this->sendError('Verification Failed.', $result, 500);
        }

        return $this->sendResponse($result, 'Encrypted document verified successfully.');
    }

    /**
     * List encryption logs.
     */
    public function encryptionLogs(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.',
            ], 401);
        }

        if (!$this->canManageCryptography($user)) {
            return $this->sendError('Permission Denied.', [
                'error' => 'Only Admin, Security Officer, or Document Controller can view encryption logs.',
            ], 403);
        }

        $logs = DocumentEncryptionLog::with([
                'document:id,document_code,title,encryption_status,status,scan_status',
                'performer:id,name,email',
            ])
            ->when($request->filled('document_id'), function ($query) use ($request) {
                $query->where('document_id', $request->document_id);
            })
            ->when($request->filled('action'), function ($query) use ($request) {
                $query->where('action', $request->action);
            })
            ->when($request->filled('status'), function ($query) use ($request) {
                $query->where('status', $request->status);
            })
            ->latest()
            ->get();

        return $this->sendResponse($logs, 'Document encryption logs retrieved successfully.');
    }

    /**
     * Encryption dashboard summary.
     */
    public function encryptionSummary(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.',
            ], 401);
        }

        if (!$this->canManageCryptography($user)) {
            return $this->sendError('Permission Denied.', [
                'error' => 'Only Admin, Security Officer, or Document Controller can view encryption summary.',
            ], 403);
        }

        $summary = [
            'total_documents' => Document::count(),
            'clean_active_documents' => Document::where('status', 'active')
                ->where('scan_status', 'clean')
                ->count(),
            'encrypted_documents' => Document::where('encryption_status', 'encrypted')->count(),
            'not_encrypted_documents' => Document::where('encryption_status', 'not_encrypted')->count(),
            'failed_encryption_documents' => Document::where('encryption_status', 'failed')->count(),
            'pending_encryption_documents' => Document::where('encryption_status', 'pending')->count(),
        ];

        return $this->sendResponse($summary, 'Encryption summary retrieved successfully.');
    }
}