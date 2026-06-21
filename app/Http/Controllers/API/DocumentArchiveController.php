<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController as BaseController;
use App\Models\Document;
use App\Models\DocumentArchiveLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class DocumentArchiveController extends BaseController
{
    /**
     * Users who can archive and restore documents.
     */
    private function canManageArchives($user): bool
    {
        return $user && (
            $user->isAdmin()
            || $user->hasPermission('manage_documents')
            || in_array($user->role?->slug, [
                'admin',
                'project_manager',
                'document_controller',
                'security_officer',
            ], true)
        );
    }

    /**
     * Users who can view archive records.
     */
    private function canViewArchives($user): bool
    {
        return $user && (
            $this->canManageArchives($user)
            || $user->hasPermission('view_documents')
            || in_array($user->role?->slug, [
                'auditor',
                'geologist',
                'engineer',
            ], true)
        );
    }

    /**
     * Base relations used by archive responses.
     */
    private function documentRelations(): array
    {
        return [
            'project:id,name,code,status,security_level',
            'category:id,name,slug',
            'uploader:id,name,email',
            'archiver:id,name,email',
            'archiveRestorer:id,name,email',
        ];
    }

    /**
     * List archived documents.
     */
    public function archivedDocuments(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.',
            ], 401);
        }

        if (!$this->canViewArchives($user)) {
            return $this->sendError('Permission Denied.', [
                'error' => 'You are not allowed to view archived documents.',
            ], 403);
        }

        $query = Document::with($this->documentRelations())
            ->where('status', 'archived');

        if (!$this->canManageArchives($user)) {
            $query->where('uploaded_by', $user->id);
        }

        $query->when($request->filled('project_id'), function ($q) use ($request) {
            $q->where('project_id', $request->project_id);
        });

        $query->when($request->filled('document_category_id'), function ($q) use ($request) {
            $q->where('document_category_id', $request->document_category_id);
        });

        $query->when($request->filled('archived_by'), function ($q) use ($request) {
            $q->where('archived_by', $request->archived_by);
        });

        $query->when($request->filled('date_from'), function ($q) use ($request) {
            $q->whereDate('archived_at', '>=', $request->date_from);
        });

        $query->when($request->filled('date_to'), function ($q) use ($request) {
            $q->whereDate('archived_at', '<=', $request->date_to);
        });

        $query->when($request->filled('search'), function ($q) use ($request) {
            $search = trim((string) $request->search);

            $q->where(function ($subQuery) use ($search) {
                $subQuery->where('title', 'LIKE', '%' . $search . '%')
                    ->orWhere('document_code', 'LIKE', '%' . $search . '%')
                    ->orWhere('description', 'LIKE', '%' . $search . '%')
                    ->orWhere('original_file_name', 'LIKE', '%' . $search . '%')
                    ->orWhere('archive_reason', 'LIKE', '%' . $search . '%');
            });
        });

        $documents = $query
            ->orderByDesc('archived_at')
            ->orderByDesc('updated_at')
            ->get();

        return $this->sendResponse(
            $documents,
            'Archived documents retrieved successfully.'
        );
    }

    /**
     * Archive one active, safe document.
     */
    public function archiveDocument(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.',
            ], 401);
        }

        if (!$this->canManageArchives($user)) {
            return $this->sendError('Permission Denied.', [
                'error' => 'Only Admin, Project Manager, Document Controller, or Security Officer can archive documents.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'reason' => [
                'nullable',
                'string',
                'max:1000',
            ],
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors(), 422);
        }

        $document = Document::find($id);

        if (!$document) {
            return $this->sendError('Not Found.', [
                'error' => 'Document not found.',
            ], 404);
        }

        if ($document->status === 'archived') {
            return $this->sendError('Archive Failed.', [
                'error' => 'This document is already archived.',
            ], 422);
        }

        if (in_array($document->status, [
            'quarantined',
            'pending_scan',
            'rejected',
            'infected',
            'blocked',
        ], true)) {
            return $this->sendError('Archive Blocked.', [
                'error' => 'Only clean active documents can be archived. Quarantined, pending, rejected, infected, or blocked documents must stay in the security workflow.',
                'document_status' => $document->status,
                'scan_status' => $document->scan_status,
            ], 422);
        }

        if ($document->scan_status !== 'clean') {
            return $this->sendError('Archive Blocked.', [
                'error' => 'Document must pass antivirus scan before it can be archived.',
                'scan_status' => $document->scan_status,
            ], 422);
        }

        if (in_array($document->sandbox_status, ['pending', 'unsafe', 'failed'], true)) {
            return $this->sendError('Archive Blocked.', [
                'error' => 'Document cannot be archived while sandbox status is pending, unsafe, or failed.',
                'sandbox_status' => $document->sandbox_status,
            ], 422);
        }

        $statusBefore = $document->status;
        $reason = $request->input('reason', 'Document archived by ' . $user->name . '.');
        $metadata = $document->metadata ?? [];

        if (!is_array($metadata)) {
            $metadata = [];
        }

        $metadata['archived_by'] = $user->id;
        $metadata['archived_by_name'] = $user->name;
        $metadata['archived_at'] = now()->toDateTimeString();
        $metadata['archive_reason'] = $reason;

        $document->update([
            'status' => 'archived',
            'archived_by' => $user->id,
            'archived_at' => now(),
            'archive_reason' => $reason,
            'restored_by' => null,
            'restored_at' => null,
            'restore_reason' => null,
            'metadata' => $metadata,
        ]);

        DocumentArchiveLog::create([
            'document_id' => $document->id,
            'performed_by' => $user->id,
            'action' => 'archived',
            'status_before' => $statusBefore,
            'status_after' => 'archived',
            'reason' => $reason,
            'metadata' => [
                'document_code' => $document->document_code,
                'title' => $document->title,
            ],
        ]);

        $document->refresh()->load($this->documentRelations());

        return $this->sendResponse($document, 'Document archived successfully.');
    }

    /**
     * Restore an archived document to active status.
     */
    public function restoreDocument(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.',
            ], 401);
        }

        if (!$this->canManageArchives($user)) {
            return $this->sendError('Permission Denied.', [
                'error' => 'Only Admin, Project Manager, Document Controller, or Security Officer can restore archived documents.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'reason' => [
                'nullable',
                'string',
                'max:1000',
            ],
            'status' => [
                'nullable',
                Rule::in(['active']),
            ],
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors(), 422);
        }

        $document = Document::where('status', 'archived')->find($id);

        if (!$document) {
            return $this->sendError('Not Found.', [
                'error' => 'Archived document not found.',
            ], 404);
        }

        if ($document->scan_status !== 'clean') {
            return $this->sendError('Restore Blocked.', [
                'error' => 'Archived document cannot be restored because it is not marked clean.',
                'scan_status' => $document->scan_status,
            ], 422);
        }

        if (in_array($document->sandbox_status, ['unsafe', 'failed'], true)) {
            return $this->sendError('Restore Blocked.', [
                'error' => 'Archived document cannot be restored because sandbox status is unsafe or failed.',
                'sandbox_status' => $document->sandbox_status,
            ], 422);
        }

        $reason = $request->input('reason', 'Archived document restored by ' . $user->name . '.');
        $metadata = $document->metadata ?? [];

        if (!is_array($metadata)) {
            $metadata = [];
        }

        $metadata['restored_by'] = $user->id;
        $metadata['restored_by_name'] = $user->name;
        $metadata['restored_at'] = now()->toDateTimeString();
        $metadata['restore_reason'] = $reason;

        $document->update([
            'status' => 'active',
            'restored_by' => $user->id,
            'restored_at' => now(),
            'restore_reason' => $reason,
            'metadata' => $metadata,
        ]);

        DocumentArchiveLog::create([
            'document_id' => $document->id,
            'performed_by' => $user->id,
            'action' => 'restored',
            'status_before' => 'archived',
            'status_after' => 'active',
            'reason' => $reason,
            'metadata' => [
                'document_code' => $document->document_code,
                'title' => $document->title,
            ],
        ]);

        $document->refresh()->load($this->documentRelations());

        return $this->sendResponse($document, 'Archived document restored successfully.');
    }

    /**
     * Archive audit logs.
     */
    public function archiveLogs(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.',
            ], 401);
        }

        if (!$this->canManageArchives($user)) {
            return $this->sendError('Permission Denied.', [
                'error' => 'Only Admin, Project Manager, Document Controller, or Security Officer can view archive logs.',
            ], 403);
        }

        $logs = DocumentArchiveLog::with([
                'document:id,document_code,title,status,scan_status,sandbox_status',
                'performer:id,name,email',
            ])
            ->when($request->filled('document_id'), function ($query) use ($request) {
                $query->where('document_id', $request->document_id);
            })
            ->when($request->filled('action'), function ($query) use ($request) {
                $query->where('action', $request->action);
            })
            ->latest()
            ->get();

        return $this->sendResponse($logs, 'Document archive logs retrieved successfully.');
    }

    /**
     * Archive dashboard summary.
     */
    public function archiveSummary(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.',
            ], 401);
        }

        if (!$this->canViewArchives($user)) {
            return $this->sendError('Permission Denied.', [
                'error' => 'You are not allowed to view archive summary.',
            ], 403);
        }

        $baseQuery = Document::query();

        if (!$this->canManageArchives($user)) {
            $baseQuery->where('uploaded_by', $user->id);
        }

        $restoredThisMonthQuery = DocumentArchiveLog::where('action', 'restored')
            ->whereYear('created_at', now()->year)
            ->whereMonth('created_at', now()->month);

        if (!$this->canManageArchives($user)) {
            $restoredThisMonthQuery->whereHas('document', function ($query) use ($user) {
                $query->where('uploaded_by', $user->id);
            });
        }

        $summary = [
            'total_documents' => (clone $baseQuery)->count(),
            'active_documents' => (clone $baseQuery)->where('status', 'active')->count(),
            'archived_documents' => (clone $baseQuery)->where('status', 'archived')->count(),
            'archived_this_month' => (clone $baseQuery)
                ->where('status', 'archived')
                ->whereYear('archived_at', now()->year)
                ->whereMonth('archived_at', now()->month)
                ->count(),
            'restored_this_month' => $restoredThisMonthQuery->count(),
        ];

        return $this->sendResponse($summary, 'Archive summary retrieved successfully.');
    }
}