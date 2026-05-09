<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController as BaseController;
use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

class DocumentController extends BaseController
{
    /**
     * Users who can upload documents.
     */
    private function canUploadDocuments($user): bool
    {
        return $user && in_array($user->role?->slug, [
            'admin',
            'geologist',
            'engineer',
            'project_manager',
            'document_controller',
        ]);
    }

    /**
     * Users who can manage documents.
     */
    private function canManageDocuments($user): bool
    {
        return $user && in_array($user->role?->slug, [
            'admin',
            'project_manager',
            'document_controller',
            'security_officer',
        ]);
    }

    /**
     * Allowed file extensions.
     */
    private function allowedExtensions(): array
    {
        return [
            'pdf',
            'doc',
            'docx',
            'xls',
            'xlsx',
            'ppt',
            'pptx',
            'jpg',
            'jpeg',
            'png',
            'tif',
            'tiff',
            'txt',
            'csv',
            'dwg',
            'dxf',
        ];
    }

    /**
     * Check if normal user can see a blocked/quarantined document metadata.
     */
    private function canSeeSensitiveDocument($user, Document $document): bool
    {
        if ($this->canManageDocuments($user)) {
            return true;
        }

        return (int) $document->uploaded_by === (int) $user->id;
    }

    /**
     * Display documents.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.',
            ], 401);
        }

        $query = Document::with([
            'project:id,name,code,status,security_level',
            'category:id,name,slug',
            'uploader:id,name,email',
            'approver:id,name,email',
        ]);

        /*
        |--------------------------------------------------------------------------
        | Visibility rule
        |--------------------------------------------------------------------------
        | Admin, Project Manager, Document Controller, and Security Officer can
        | see quarantined/rejected documents.
        |
        | Normal users cannot see other users' quarantined/rejected documents.
        | But they can see their own uploaded quarantined/rejected documents
        | so they know the file is waiting for scan or was rejected.
        */
        if (!$this->canManageDocuments($user)) {
            $query->where(function ($q) use ($user) {
                $q->whereNotIn('status', [
                    'quarantined',
                    'rejected',
                    'infected',
                    'blocked',
                ])->orWhere(function ($ownerQuery) use ($user) {
                    $ownerQuery
                        ->where('uploaded_by', $user->id)
                        ->whereIn('status', [
                            'quarantined',
                            'rejected',
                            'infected',
                            'blocked',
                        ]);
                });
            });
        }

        /*
        |--------------------------------------------------------------------------
        | Filters
        |--------------------------------------------------------------------------
        */
        $query->when($request->filled('project_id'), function ($q) use ($request) {
            $q->where('project_id', $request->project_id);
        });

        $query->when($request->filled('document_category_id'), function ($q) use ($request) {
            $q->where('document_category_id', $request->document_category_id);
        });

        $query->when($request->filled('document_type'), function ($q) use ($request) {
            $q->where('document_type', $request->document_type);
        });

        $query->when($request->filled('security_level'), function ($q) use ($request) {
            $q->where('security_level', $request->security_level);
        });

        $query->when($request->filled('status'), function ($q) use ($request) {
            $q->where('status', $request->status);
        });

        $query->when($request->filled('scan_status'), function ($q) use ($request) {
            $q->where('scan_status', $request->scan_status);
        });

        $query->when($request->filled('search'), function ($q) use ($request) {
            $q->where(function ($subQuery) use ($request) {
                $subQuery->where('title', 'LIKE', '%' . $request->search . '%')
                    ->orWhere('document_code', 'LIKE', '%' . $request->search . '%')
                    ->orWhere('description', 'LIKE', '%' . $request->search . '%')
                    ->orWhere('original_file_name', 'LIKE', '%' . $request->search . '%');
            });
        });

        $documents = $query
            ->orderBy('created_at', 'desc')
            ->get();

        return $this->sendResponse($documents, 'Documents retrieved successfully.');
    }

    /**
     * Upload new document.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.',
            ], 401);
        }

        if (!$this->canUploadDocuments($user)) {
            return $this->sendError('Permission Denied.', [
                'error' => 'You are not allowed to upload documents.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'project_id' => [
                'nullable',
                'integer',
                'exists:projects,id',
            ],
            'document_category_id' => [
                'required',
                'integer',
                'exists:document_categories,id',
            ],
            'title' => [
                'required',
                'string',
                'max:255',
            ],
            'description' => [
                'nullable',
                'string',
            ],
            'document_type' => [
                'nullable',
                Rule::in([
                    'geological_report',
                    'technical_drawing',
                    'construction_record',
                    'survey_map',
                    'contract',
                    'plain_text',
                    'image',
                    'spreadsheet',
                    'presentation',
                    'other',
                ]),
            ],
            'security_level' => [
                'nullable',
                Rule::in([
                    'public',
                    'internal',
                    'confidential',
                    'restricted',
                ]),
            ],
            'tags' => [
                'nullable',
                'array',
            ],
            'tags.*' => [
                'nullable',
                'string',
                'max:100',
            ],
            'metadata' => [
                'nullable',
                'array',
            ],
            'file' => [
                'required',
                'file',
                'max:102400', // 100 MB
            ],
        ]);

        /*
        |--------------------------------------------------------------------------
        | Custom extension check
        |--------------------------------------------------------------------------
        | We use this because some technical files like DWG/DXF may not validate
        | well using only MIME type.
        */
        $validator->after(function ($validator) use ($request) {
            if ($request->hasFile('file')) {
                $extension = strtolower($request->file('file')->getClientOriginalExtension());

                if (!in_array($extension, $this->allowedExtensions(), true)) {
                    $validator->errors()->add(
                        'file',
                        'This file type is not allowed. Allowed types: ' . implode(', ', $this->allowedExtensions())
                    );
                }
            }
        });

        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors(), 422);
        }

        /*
        |--------------------------------------------------------------------------
        | Check category status
        |--------------------------------------------------------------------------
        */
        $category = DocumentCategory::where('id', $request->document_category_id)
            ->where('status', 'active')
            ->first();

        if (!$category) {
            return $this->sendError('Invalid Category.', [
                'error' => 'The selected document category is not active.',
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | Check project status
        |--------------------------------------------------------------------------
        */
        if ($request->project_id) {
            $project = Project::find($request->project_id);

            if (!$project) {
                return $this->sendError('Invalid Project.', [
                    'error' => 'Project not found.',
                ], 404);
            }

            if ($project->status === 'archived') {
                return $this->sendError('Invalid Project.', [
                    'error' => 'You cannot upload documents to an archived project.',
                ], 422);
            }
        }

        try {
            $file = $request->file('file');

            $originalFileName = $file->getClientOriginalName();
            $extension = strtolower($file->getClientOriginalExtension());
            $mimeType = $file->getMimeType();
            $fileSize = $file->getSize();

            /*
            |--------------------------------------------------------------------------
            | SHA256 hash
            |--------------------------------------------------------------------------
            | Used to detect duplicate/tampered files.
            */
            $sha256Hash = hash_file('sha256', $file->getRealPath());

            /*
            |--------------------------------------------------------------------------
            | Real quarantine storage
            |--------------------------------------------------------------------------
            | File is stored in private local disk under quarantine folder.
            | It is NOT trusted yet. It must pass antivirus scan next.
            */
            $documentCode = 'DOC-' . now()->format('YmdHis') . '-' . strtoupper(Str::random(6));
            $storedFileName = $documentCode . '.' . $extension;

            $folder = 'dms/quarantine/' . now()->format('Y/m');

            $filePath = Storage::disk('local')->putFileAs(
                $folder,
                $file,
                $storedFileName
            );

            $metadata = $request->metadata ?? [];

            if (!is_array($metadata)) {
                $metadata = [];
            }

            $metadata = array_merge($metadata, [
                'quarantine_reason' => 'new_upload_pending_antivirus_scan',
                'quarantined_at' => now()->toDateTimeString(),
                'quarantine_message' => 'Document uploaded but not trusted yet. Waiting for antivirus scan.',
                'original_storage_folder' => $folder,
            ]);

            $document = Document::create([
                'project_id' => $request->project_id,
                'document_category_id' => $request->document_category_id,
                'uploaded_by' => $user->id,

                'document_code' => $documentCode,
                'title' => $request->title,
                'slug' => Str::slug($request->title) . '-' . strtolower(Str::random(6)),
                'description' => $request->description,

                'document_type' => $request->document_type ?? 'other',

                'original_file_name' => $originalFileName,
                'stored_file_name' => $storedFileName,
                'file_path' => $filePath,
                'disk' => 'local',
                'mime_type' => $mimeType,
                'extension' => $extension,
                'file_size' => $fileSize,
                'sha256_hash' => $sha256Hash,

                'version_number' => 1,
                'security_level' => $request->security_level ?? 'internal',

                /*
                |--------------------------------------------------------------------------
                | Real quarantine workflow defaults
                |--------------------------------------------------------------------------
                | After upload, the document is NOT trusted.
                | It is saved in quarantine and cannot be opened/downloaded
                | until antivirus scan and security checks pass.
                */
                'status' => 'quarantined',
                'scan_status' => 'pending',
                'scan_message' => 'Document uploaded successfully and placed in quarantine. Waiting for antivirus scan.',

                'encryption_status' => 'not_encrypted',
                'plaintext_status' => 'not_extracted',
                'sandbox_status' => 'not_tested',
                'ai_status' => 'not_analyzed',

                'tags' => $request->tags,
                'metadata' => $metadata,
            ]);

            $document->load([
                'project:id,name,code,status,security_level',
                'category:id,name,slug',
                'uploader:id,name,email',
            ]);

            return $this->sendResponse(
                $document,
                'Document uploaded successfully and placed in quarantine. It must be scanned before active use.'
            );

        } catch (Throwable $e) {
            return $this->sendError('Upload Failed.', [
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Show one document metadata.
     */
    public function show(Request $request, string $id): JsonResponse
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
            'approver:id,name,email',
        ])->find($id);

        if (!$document) {
            return $this->sendError('Not Found.', [
                'error' => 'Document not found.',
            ], 404);
        }

        if (
            in_array($document->status, ['quarantined', 'rejected', 'infected', 'blocked'], true) &&
            !$this->canSeeSensitiveDocument($user, $document)
        ) {
            return $this->sendError('Permission Denied.', [
                'error' => 'You are not allowed to view this document metadata.',
            ], 403);
        }

        return $this->sendResponse($document, 'Document retrieved successfully.');
    }

    /**
     * Update document metadata.
     * This does not replace the file.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.',
            ], 401);
        }

        $document = Document::find($id);

        if (!$document) {
            return $this->sendError('Not Found.', [
                'error' => 'Document not found.',
            ], 404);
        }

        if (!$this->canManageDocuments($user) && (int) $document->uploaded_by !== (int) $user->id) {
            return $this->sendError('Permission Denied.', [
                'error' => 'You are not allowed to update this document.',
            ], 403);
        }

        if ($document->status === 'rejected' && !$this->canManageDocuments($user)) {
            return $this->sendError('Update Blocked.', [
                'error' => 'Rejected document cannot be updated by normal users.',
            ], 400);
        }

        $validator = Validator::make($request->all(), [
            'project_id' => [
                'nullable',
                'integer',
                'exists:projects,id',
            ],
            'document_category_id' => [
                'nullable',
                'integer',
                'exists:document_categories,id',
            ],
            'title' => [
                'nullable',
                'string',
                'max:255',
            ],
            'description' => [
                'nullable',
                'string',
            ],
            'document_type' => [
                'nullable',
                Rule::in([
                    'geological_report',
                    'technical_drawing',
                    'construction_record',
                    'survey_map',
                    'contract',
                    'plain_text',
                    'image',
                    'spreadsheet',
                    'presentation',
                    'other',
                ]),
            ],
            'security_level' => [
                'nullable',
                Rule::in([
                    'public',
                    'internal',
                    'confidential',
                    'restricted',
                ]),
            ],
            'tags' => [
                'nullable',
                'array',
            ],
            'tags.*' => [
                'nullable',
                'string',
                'max:100',
            ],
            'metadata' => [
                'nullable',
                'array',
            ],
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors(), 422);
        }

        if ($request->filled('document_category_id')) {
            $category = DocumentCategory::where('id', $request->document_category_id)
                ->where('status', 'active')
                ->first();

            if (!$category) {
                return $this->sendError('Invalid Category.', [
                    'error' => 'The selected document category is not active.',
                ], 422);
            }
        }

        if ($request->filled('project_id')) {
            $project = Project::find($request->project_id);

            if (!$project) {
                return $this->sendError('Invalid Project.', [
                    'error' => 'Project not found.',
                ], 404);
            }

            if ($project->status === 'archived') {
                return $this->sendError('Invalid Project.', [
                    'error' => 'You cannot assign documents to an archived project.',
                ], 422);
            }
        }

        $payload = [];

        foreach ([
            'project_id',
            'document_category_id',
            'title',
            'description',
            'document_type',
            'security_level',
            'tags',
            'metadata',
        ] as $field) {
            if ($request->has($field)) {
                $payload[$field] = $request->{$field};
            }
        }

        if (isset($payload['title'])) {
            $payload['slug'] = Str::slug($payload['title']) . '-' . strtolower(Str::random(6));
        }

        $document->update($payload);

        $document->refresh()->load([
            'project:id,name,code,status,security_level',
            'category:id,name,slug',
            'uploader:id,name,email',
            'approver:id,name,email',
        ]);

        return $this->sendResponse($document, 'Document updated successfully.');
    }

    /**
     * Archive document.
     * We do not physically remove the file here because DMS needs audit trace.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.',
            ], 401);
        }

        if (!$this->canManageDocuments($user)) {
            return $this->sendError('Permission Denied.', [
                'error' => 'Only Admin, Project Manager, Document Controller, or Security Officer can archive documents.',
            ], 403);
        }

        $document = Document::find($id);

        if (!$document) {
            return $this->sendError('Not Found.', [
                'error' => 'Document not found.',
            ], 404);
        }

        $metadata = $document->metadata ?? [];

        if (!is_array($metadata)) {
            $metadata = [];
        }

        $metadata['archived_by'] = $user->id;
        $metadata['archived_by_name'] = $user->name;
        $metadata['archived_at'] = now()->toDateTimeString();

        $document->update([
            'status' => 'archived',
            'metadata' => $metadata,
        ]);

        $document->refresh()->load([
            'project:id,name,code,status,security_level',
            'category:id,name,slug',
            'uploader:id,name,email',
            'approver:id,name,email',
        ]);

        return $this->sendResponse($document, 'Document archived successfully.');
    }
}