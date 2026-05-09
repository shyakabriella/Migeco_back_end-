<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController as BaseController;
use App\Models\Document;
use App\Models\DocumentScanLog;
use App\Services\DocumentAntivirusScanner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DocumentSecurityController extends BaseController
{
    /**
     * Users who can scan and manage quarantine.
     */
    private function canManageSecurity($user): bool
    {
        return $user && in_array($user->role?->slug, [
            'admin',
            'security_officer',
            'document_controller',
        ]);
    }

    /**
     * Scan one document.
     */
    public function scanDocument(
        Request $request,
        string $id,
        DocumentAntivirusScanner $scanner
    ): JsonResponse {
        $user = $request->user();

        if (!$user) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.',
            ], 401);
        }

        if (!$this->canManageSecurity($user)) {
            return $this->sendError('Permission Denied.', [
                'error' => 'Only Admin, Security Officer, or Document Controller can scan documents.',
            ], 403);
        }

        $document = Document::find($id);

        if (!$document) {
            return $this->sendError('Not Found.', [
                'error' => 'Document not found.',
            ], 404);
        }

        if ($document->status === 'rejected') {
            return $this->sendError('Scan Blocked.', [
                'error' => 'Rejected document cannot be scanned again.',
            ], 400);
        }

        if ($document->status === 'archived') {
            return $this->sendError('Scan Blocked.', [
                'error' => 'Archived document cannot be scanned.',
            ], 400);
        }

        /*
        |--------------------------------------------------------------------------
        | Scanner service controls the final result
        |--------------------------------------------------------------------------
        | The document may currently be quarantined or pending_scan.
        | Scanner should update scan_status/status according to clean,
        | infected, suspicious, or failed result.
        */
        $result = $scanner->scan($document, $user, 'manual');

        $document->refresh()->load([
            'project:id,name,code,status,security_level',
            'category:id,name,slug',
            'uploader:id,name,email',
            'scanLogs' => function ($query) {
                $query->latest()->limit(5);
            },
        ]);

        return $this->sendResponse([
            'scan_result' => $result,
            'document' => $document,
        ], 'Document scan completed.');
    }

    /**
     * Scan all pending/quarantined documents.
     */
    public function scanPendingDocuments(
        Request $request,
        DocumentAntivirusScanner $scanner
    ): JsonResponse {
        $user = $request->user();

        if (!$user) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.',
            ], 401);
        }

        if (!$this->canManageSecurity($user)) {
            return $this->sendError('Permission Denied.', [
                'error' => 'Only Admin, Security Officer, or Document Controller can scan pending documents.',
            ], 403);
        }

        /*
        |--------------------------------------------------------------------------
        | Important update
        |--------------------------------------------------------------------------
        | New uploads are now stored as status = quarantined.
        | Old documents may still have status = pending_scan.
        | We support both so old records still work.
        */
        $documents = Document::where('scan_status', 'pending')
            ->whereIn('status', [
                'quarantined',
                'pending_scan',
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
                'status_before_scan' => $document->status,
                'result' => $scanner->scan($document, $user, 'batch'),
            ];
        }

        return $this->sendResponse([
            'total_scanned' => count($results),
            'results' => $results,
        ], 'Pending and quarantined documents scanned successfully.');
    }

    /**
     * List quarantined documents.
     */
    public function quarantinedDocuments(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.',
            ], 401);
        }

        if (!$this->canManageSecurity($user)) {
            return $this->sendError('Permission Denied.', [
                'error' => 'Only Admin, Security Officer, or Document Controller can view quarantine.',
            ], 403);
        }

        $documents = Document::with([
                'project:id,name,code',
                'category:id,name,slug',
                'uploader:id,name,email',
                'scanLogs' => function ($query) {
                    $query->latest()->limit(3);
                },
            ])
            ->where('status', 'quarantined')
            ->latest()
            ->get();

        return $this->sendResponse($documents, 'Quarantined documents retrieved successfully.');
    }

    /**
     * Reject quarantined document permanently.
     * This keeps record in database but blocks the document.
     */
    public function rejectQuarantinedDocument(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.',
            ], 401);
        }

        if (!$this->canManageSecurity($user)) {
            return $this->sendError('Permission Denied.', [
                'error' => 'Only Admin, Security Officer, or Document Controller can reject quarantined documents.',
            ], 403);
        }

        $document = Document::where('status', 'quarantined')->find($id);

        if (!$document) {
            return $this->sendError('Not Found.', [
                'error' => 'Quarantined document not found.',
            ], 404);
        }

        $metadata = $document->metadata ?? [];

        if (!is_array($metadata)) {
            $metadata = [];
        }

        $metadata['rejected_by'] = $user->id;
        $metadata['rejected_by_name'] = $user->name;
        $metadata['rejected_at'] = now()->toDateTimeString();
        $metadata['rejection_reason'] = $request->input(
            'reason',
            'Document rejected after quarantine review.'
        );

        $document->update([
            'status' => 'rejected',
            'scan_message' => 'Document rejected after quarantine review by ' . $user->name,
            'metadata' => $metadata,
        ]);

        $document->refresh()->load([
            'project:id,name,code',
            'category:id,name,slug',
            'uploader:id,name,email',
            'scanLogs' => function ($query) {
                $query->latest()->limit(3);
            },
        ]);

        return $this->sendResponse($document, 'Quarantined document rejected successfully.');
    }

    /**
     * Scan logs.
     */
    public function scanLogs(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.',
            ], 401);
        }

        if (!$this->canManageSecurity($user)) {
            return $this->sendError('Permission Denied.', [
                'error' => 'Only Admin, Security Officer, or Document Controller can view scan logs.',
            ], 403);
        }

        $logs = DocumentScanLog::with([
                'document:id,document_code,title,status,scan_status',
                'scanner:id,name,email',
            ])
            ->when($request->filled('status'), function ($query) use ($request) {
                $query->where('status', $request->status);
            })
            ->when($request->filled('document_id'), function ($query) use ($request) {
                $query->where('document_id', $request->document_id);
            })
            ->latest()
            ->get();

        return $this->sendResponse($logs, 'Document scan logs retrieved successfully.');
    }
}