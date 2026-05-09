<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController as BaseController;
use App\Models\Document;
use App\Models\User;
use App\Services\DocumentEncryptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DocumentAccessController extends BaseController
{
    /**
     * Roles allowed to view confidential documents.
     */
    private function confidentialAllowedRoles(): array
    {
        return [
            'admin',
            'project_manager',
            'document_controller',
            'security_officer',
            'auditor',
            'geologist',
            'engineer',
        ];
    }

    /**
     * Roles allowed to view restricted documents.
     */
    private function restrictedAllowedRoles(): array
    {
        return [
            'admin',
            'project_manager',
            'document_controller',
            'security_officer',
            'auditor',
        ];
    }

    /**
     * Check if user can view document.
     */
    private function canViewDocument(User $user, Document $document): bool
    {
        if (!$user->hasPermission('view_documents') && !$user->isAdmin()) {
            return false;
        }

        $roleSlug = $user->role?->slug;

        /*
        |--------------------------------------------------------------------------
        | Public and internal documents
        |--------------------------------------------------------------------------
        */
        if (in_array($document->security_level, ['public', 'internal'], true)) {
            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | Confidential documents
        |--------------------------------------------------------------------------
        */
        if ($document->security_level === 'confidential') {
            return in_array($roleSlug, $this->confidentialAllowedRoles(), true)
                || $document->uploaded_by === $user->id;
        }

        /*
        |--------------------------------------------------------------------------
        | Restricted documents
        |--------------------------------------------------------------------------
        */
        if ($document->security_level === 'restricted') {
            return in_array($roleSlug, $this->restrictedAllowedRoles(), true);
        }

        return false;
    }

    /**
     * Check if user can download document.
     */
    private function canDownloadDocument(User $user, Document $document): bool
    {
        if (!$this->canViewDocument($user, $document)) {
            return false;
        }

        return $user->hasPermission('download_documents') || $user->isAdmin();
    }

    /**
     * Validate document security before view/download.
     *
     * This prevents unsafe files from working.
     */
    private function validateDocumentSecurity(Document $document): ?array
    {
        /*
        |--------------------------------------------------------------------------
        | Rejected document
        |--------------------------------------------------------------------------
        */
        if ($document->isRejected()) {
            return [
                'message' => 'Document access blocked.',
                'error' => 'This document was rejected and cannot be opened.',
                'status_code' => 403,
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | Quarantined or infected document
        |--------------------------------------------------------------------------
        */
        if ($document->isQuarantined() || $document->isInfected()) {
            return [
                'message' => 'Document access blocked.',
                'error' => 'This document is quarantined or infected and cannot be opened.',
                'status_code' => 403,
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | Pending antivirus scan
        |--------------------------------------------------------------------------
        */
        if ($document->isPendingScan()) {
            return [
                'message' => 'Document not ready.',
                'error' => 'This document is still waiting for antivirus scan.',
                'status_code' => 403,
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | Antivirus failed
        |--------------------------------------------------------------------------
        */
        if ($document->scan_status === 'failed') {
            return [
                'message' => 'Document access blocked.',
                'error' => 'Antivirus scan failed. Please contact Security Officer.',
                'status_code' => 403,
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | Sandbox validation
        |--------------------------------------------------------------------------
        | If DMS_REQUIRE_SAFE_SANDBOX=true, the document cannot open until
        | sandbox_status = safe.
        */
        if ((bool) config('dms.sandbox.require_safe_sandbox_for_access', true)) {
            if ($document->isSandboxNotTested()) {
                return [
                    'message' => 'Document not ready.',
                    'error' => 'This document has not passed sandbox inspection yet.',
                    'status_code' => 403,
                ];
            }

            if ($document->isSandboxPending()) {
                return [
                    'message' => 'Document not ready.',
                    'error' => 'This document is still under sandbox inspection.',
                    'status_code' => 403,
                ];
            }

            if ($document->isSandboxFailed()) {
                return [
                    'message' => 'Document access blocked.',
                    'error' => 'Sandbox inspection failed. Please contact Security Officer.',
                    'status_code' => 403,
                ];
            }

            if ($document->isSandboxUnsafe()) {
                return [
                    'message' => 'Document access blocked.',
                    'error' => 'This document was marked unsafe by sandbox inspection.',
                    'status_code' => 403,
                ];
            }
        } else {
            /*
            |--------------------------------------------------------------------------
            | Even when sandbox is not required, unsafe documents remain blocked.
            |--------------------------------------------------------------------------
            */
            if ($document->isSandboxUnsafe()) {
                return [
                    'message' => 'Document access blocked.',
                    'error' => 'This document was marked unsafe by sandbox inspection.',
                    'status_code' => 403,
                ];
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Final safety check
        |--------------------------------------------------------------------------
        */
        if (!$document->isSafeToOpen()) {
            return [
                'message' => 'Document not safe.',
                'error' => 'This document is not marked as safe. It must be clean and sandbox-safe before access.',
                'status_code' => 403,
            ];
        }

        return null;
    }

    /**
     * Prepare file for secure access.
     *
     * If encrypted:
     * - decrypt temporarily
     * - return temporary file
     * - delete temporary file after response
     *
     * If not encrypted:
     * - return private storage file
     */
    private function prepareAccessFile(
        Document $document,
        User $user,
        string $action
    ): array {
        $disk = $document->disk ?: 'local';

        /*
        |--------------------------------------------------------------------------
        | Encrypted document access
        |--------------------------------------------------------------------------
        */
        if ($document->isEncrypted()) {
            /** @var DocumentEncryptionService $encryptionService */
            $encryptionService = app(DocumentEncryptionService::class);

            $result = $encryptionService->decryptToTemporaryFile(
                $document,
                $user,
                $action
            );

            if (($result['status'] ?? null) !== 'success') {
                return [
                    'success' => false,
                    'message' => $result['message'] ?? 'Unable to decrypt document.',
                ];
            }

            return [
                'success' => true,
                'absolute_path' => $result['temporary_absolute_path'],
                'delete_after_send' => true,
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | Normal non-encrypted document access
        |--------------------------------------------------------------------------
        */
        if (!Storage::disk($disk)->exists($document->file_path)) {
            return [
                'success' => false,
                'message' => 'The document file does not exist in storage.',
            ];
        }

        return [
            'success' => true,
            'absolute_path' => Storage::disk($disk)->path($document->file_path),
            'delete_after_send' => false,
        ];
    }

    /**
     * Headers for inline document viewing.
     */
    private function inlineHeaders(Document $document): array
    {
        return [
            'Content-Type' => $document->mime_type ?: 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="' . addslashes($document->original_file_name) . '"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ];
    }

    /**
     * Headers for secure document download.
     */
    private function downloadHeaders(Document $document): array
    {
        return [
            'Content-Type' => $document->mime_type ?: 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ];
    }

    /**
     * View document securely in browser.
     */
    public function view(Request $request, string $id): JsonResponse|BinaryFileResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.',
            ], 401);
        }

        $document = Document::with([
            'project:id,name,code,status,security_level',
            'category:id,name,slug',
            'uploader:id,name,email',
            'sandboxTester:id,name,email',
        ])->find($id);

        if (!$document) {
            return $this->sendError('Not Found.', [
                'error' => 'Document not found.',
            ], 404);
        }

        /*
        |--------------------------------------------------------------------------
        | Permission Check
        |--------------------------------------------------------------------------
        */
        if (!$this->canViewDocument($user, $document)) {
            return $this->sendError('Permission Denied.', [
                'error' => 'You are not allowed to view this document.',
            ], 403);
        }

        /*
        |--------------------------------------------------------------------------
        | Security Check
        |--------------------------------------------------------------------------
        */
        $securityError = $this->validateDocumentSecurity($document);

        if ($securityError) {
            return $this->sendError($securityError['message'], [
                'error' => $securityError['error'],
                'document_status' => $document->status,
                'scan_status' => $document->scan_status,
                'sandbox_status' => $document->sandbox_status,
                'sandbox_message' => $document->sandbox_message,
                'encryption_status' => $document->encryption_status,
            ], $securityError['status_code']);
        }

        /*
        |--------------------------------------------------------------------------
        | Prepare File
        |--------------------------------------------------------------------------
        */
        $preparedFile = $this->prepareAccessFile(
            $document,
            $user,
            'decrypt_for_view'
        );

        if (!$preparedFile['success']) {
            return $this->sendError('File Access Failed.', [
                'error' => $preparedFile['message'],
            ], 500);
        }

        /*
        |--------------------------------------------------------------------------
        | Return File Response
        |--------------------------------------------------------------------------
        */
        $response = response()->file(
            $preparedFile['absolute_path'],
            $this->inlineHeaders($document)
        );

        if ($preparedFile['delete_after_send']) {
            $response->deleteFileAfterSend(true);
        }

        return $response;
    }

    /**
     * Download document securely.
     */
    public function download(Request $request, string $id): JsonResponse|BinaryFileResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.',
            ], 401);
        }

        $document = Document::with([
            'project:id,name,code,status,security_level',
            'category:id,name,slug',
            'uploader:id,name,email',
            'sandboxTester:id,name,email',
        ])->find($id);

        if (!$document) {
            return $this->sendError('Not Found.', [
                'error' => 'Document not found.',
            ], 404);
        }

        /*
        |--------------------------------------------------------------------------
        | Permission Check
        |--------------------------------------------------------------------------
        */
        if (!$this->canDownloadDocument($user, $document)) {
            return $this->sendError('Permission Denied.', [
                'error' => 'You are not allowed to download this document.',
            ], 403);
        }

        /*
        |--------------------------------------------------------------------------
        | Security Check
        |--------------------------------------------------------------------------
        */
        $securityError = $this->validateDocumentSecurity($document);

        if ($securityError) {
            return $this->sendError($securityError['message'], [
                'error' => $securityError['error'],
                'document_status' => $document->status,
                'scan_status' => $document->scan_status,
                'sandbox_status' => $document->sandbox_status,
                'sandbox_message' => $document->sandbox_message,
                'encryption_status' => $document->encryption_status,
            ], $securityError['status_code']);
        }

        /*
        |--------------------------------------------------------------------------
        | Prepare File
        |--------------------------------------------------------------------------
        */
        $preparedFile = $this->prepareAccessFile(
            $document,
            $user,
            'decrypt_for_download'
        );

        if (!$preparedFile['success']) {
            return $this->sendError('File Access Failed.', [
                'error' => $preparedFile['message'],
            ], 500);
        }

        /*
        |--------------------------------------------------------------------------
        | Return Download Response
        |--------------------------------------------------------------------------
        */
        $response = response()->download(
            $preparedFile['absolute_path'],
            $document->original_file_name,
            $this->downloadHeaders($document)
        );

        if ($preparedFile['delete_after_send']) {
            $response->deleteFileAfterSend(true);
        }

        return $response;
    }

    /**
     * Secure document access status.
     *
     * This does not open/download the document.
     * It only tells frontend if user can view or download.
     */
    public function accessStatus(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.',
            ], 401);
        }

        $document = Document::with([
            'project:id,name,code,status,security_level',
            'category:id,name,slug',
            'uploader:id,name,email',
            'sandboxTester:id,name,email',
        ])->find($id);

        if (!$document) {
            return $this->sendError('Not Found.', [
                'error' => 'Document not found.',
            ], 404);
        }

        $securityError = $this->validateDocumentSecurity($document);

        $data = [
            'document' => [
                'id' => $document->id,
                'document_code' => $document->document_code,
                'title' => $document->title,
                'original_file_name' => $document->original_file_name,
                'mime_type' => $document->mime_type,
                'extension' => $document->extension,
                'readable_file_size' => $document->readable_file_size,

                'security_level' => $document->security_level,

                'status' => $document->status,
                'scan_status' => $document->scan_status,
                'scan_message' => $document->scan_message,

                'sandbox_status' => $document->sandbox_status,
                'sandbox_score' => $document->sandbox_score,
                'sandbox_message' => $document->sandbox_message,
                'sandbox_tested_at' => $document->sandbox_tested_at,

                'encryption_status' => $document->encryption_status,
                'encryption_algorithm' => $document->encryption_algorithm,
                'encryption_key_id' => $document->encryption_key_id,

                'plaintext_status' => $document->plaintext_status,
                'ai_status' => $document->ai_status,
            ],
            'access' => [
                'can_view' => $this->canViewDocument($user, $document) && !$securityError,
                'can_download' => $this->canDownloadDocument($user, $document) && !$securityError,
                'is_safe_to_open' => $document->isSafeToOpen(),
                'is_encrypted' => $document->isEncrypted(),
                'is_sandbox_safe' => $document->isSandboxSafe(),
                'reason_blocked' => $securityError['error'] ?? null,
            ],
        ];

        return $this->sendResponse($data, 'Document access status retrieved successfully.');
    }
}