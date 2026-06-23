<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\API\RegisterController;
use App\Http\Controllers\API\DocumentCategoryController;
use App\Http\Controllers\API\ProjectController;
use App\Http\Controllers\API\DocumentController;
use App\Http\Controllers\API\DocumentArchiveController;
use App\Http\Controllers\API\DocumentSecurityController;
use App\Http\Controllers\API\DocumentAccessController;
use App\Http\Controllers\API\DocumentEncryptionController;
use App\Http\Controllers\API\DocumentPlaintextController;
use App\Http\Controllers\API\DocumentSandboxController;
use App\Http\Controllers\API\DocumentAiController;
use App\Http\Controllers\API\GeologicalRecordController;
use App\Http\Controllers\API\MetadataSchemaController;
use App\Http\Controllers\API\RoleController;
use App\Http\Controllers\API\SettingsController;
use App\Http\Controllers\API\StudyAreaController;
use App\Http\Controllers\API\SampleLaboratoryController;

/*
|--------------------------------------------------------------------------
| Public API Routes
|--------------------------------------------------------------------------
| Only login is public.
| User registration is protected because only an authenticated Admin
| is allowed to create users.
*/
Route::controller(RegisterController::class)->group(function () {
    Route::post('login', 'login');
});

/*
|--------------------------------------------------------------------------
| Protected API Routes
|--------------------------------------------------------------------------
| Every route in this group requires a valid Laravel Sanctum Bearer token.
*/
Route::middleware('auth:sanctum')->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Authenticated User
    |--------------------------------------------------------------------------
    | These endpoints are used by different frontend pages.
    */
    Route::controller(RegisterController::class)->group(function () {
        Route::get('user', 'me');
        Route::get('me', 'me');
    });

    /*
    |--------------------------------------------------------------------------
    | Authentication / Profile / User Management Routes
    |--------------------------------------------------------------------------
    */
    Route::controller(RegisterController::class)->group(function () {
        Route::post('register', 'register');
        Route::post('logout', 'logout');
        Route::get('profile', 'profile');

        Route::get('users', 'users');
        Route::post('users', 'register');
        Route::get('users/{id}', 'showUser');
        Route::put('users/{id}', 'updateUser');
        Route::patch('users/{id}', 'updateUser');
        Route::delete('users/{id}', 'deleteUser');
    });

    /*
    |--------------------------------------------------------------------------
    | Role Routes
    |--------------------------------------------------------------------------
    | Main role routes.
    */
    Route::apiResource('roles', RoleController::class);

    /*
    |--------------------------------------------------------------------------
    | Frontend Compatibility Routes
    |--------------------------------------------------------------------------
    | Some frontend pages call:
    | - /api/admin/users
    | - /api/admin/roles
    | - /api/user-management/users
    |
    | Important:
    | The name prefixes prevent duplicate route names during php artisan optimize.
    */
    Route::prefix('admin')->name('admin.')->group(function () {
        Route::controller(RegisterController::class)->group(function () {
            Route::get('users', 'users');
            Route::post('users', 'register');
            Route::get('users/{id}', 'showUser');
            Route::put('users/{id}', 'updateUser');
            Route::patch('users/{id}', 'updateUser');
            Route::delete('users/{id}', 'deleteUser');
        });

        Route::apiResource('roles', RoleController::class);
    });

    Route::prefix('user-management')->name('user-management.')->group(function () {
        Route::controller(RegisterController::class)->group(function () {
            Route::get('users', 'users');
            Route::post('users', 'register');
            Route::get('users/{id}', 'showUser');
            Route::put('users/{id}', 'updateUser');
            Route::patch('users/{id}', 'updateUser');
            Route::delete('users/{id}', 'deleteUser');
        });

        Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
    });

    /*
    |--------------------------------------------------------------------------
    | Settings Routes
    |--------------------------------------------------------------------------
    | Supports the Settings page:
    | - Email notifications
    | - System notifications
    | - User profile management
    | - Change password
    | - Role and permission management
    | - Notification preferences
    | - System configuration
    */
    Route::prefix('settings')
        ->controller(SettingsController::class)
        ->group(function () {
            Route::get('/', 'index');

            Route::put('/', 'update');
            Route::patch('/', 'update');

            Route::put('profile', 'updateProfile');
            Route::patch('profile', 'updateProfile');

            Route::put('password', 'changePassword');

            Route::post('test-email', 'testEmail');
        });

    /*
    |--------------------------------------------------------------------------
    | Document Category Routes
    |--------------------------------------------------------------------------
    | Existing category management remains unchanged.
    */
    Route::apiResource(
        'document-categories',
        DocumentCategoryController::class
    );

    /*
    |--------------------------------------------------------------------------
    | Metadata Schema Routes
    |--------------------------------------------------------------------------
    | Adds reusable metadata structures for geological records such as:
    | boreholes, groundwater, rock samples, mineral occurrences and faults.
    |
    | Important:
    | These routes are additive and do not replace document categories.
    */
    Route::apiResource(
        'metadata-schemas',
        MetadataSchemaController::class
    );

    /*
    |--------------------------------------------------------------------------
    | Project Management Routes
    |--------------------------------------------------------------------------
    | A project is the main workspace/container.
    | Each project can return all related records:
    | documents, archives, study-area files, samples, laboratory records,
    | geological records, and security alerts.
    |
    | Important:
    | Summary and records routes must be declared before apiResource.
    | Otherwise, Laravel may treat "summary" or "records" as a project ID.
    */
    Route::get(
        'projects/summary',
        [ProjectController::class, 'summary']
    )->name('projects.summary');

    Route::get(
        'projects/{id}/records',
        [ProjectController::class, 'records']
    )->name('projects.records');

    Route::post(
        'projects/{id}/archive',
        [ProjectController::class, 'archive']
    )->name('projects.archive');

    Route::post(
        'projects/{id}/restore',
        [ProjectController::class, 'restore']
    )->name('projects.restore');

    Route::apiResource(
        'projects',
        ProjectController::class
    );

    /*
    |--------------------------------------------------------------------------
    | Study Area Management Routes
    |--------------------------------------------------------------------------
    | Study areas store the exact supervisor-required information:
    | area name, GPS coordinates/location, description, photos, status,
    | map details, and field information.
    |
    | Important:
    | Summary and custom photo routes must be declared before apiResource.
    | Otherwise, Laravel may treat "summary" as a study area ID.
    */
    Route::get(
        'study-areas/summary',
        [StudyAreaController::class, 'summary']
    )->name('study-areas.summary');

    Route::post(
        'study-areas/{id}/restore',
        [StudyAreaController::class, 'restore']
    )->name('study-areas.restore');

    Route::delete(
        'study-areas/{studyAreaId}/photos/{photoId}',
        [StudyAreaController::class, 'deletePhoto']
    )->name('study-areas.photos.delete');

    Route::apiResource(
        'study-areas',
        StudyAreaController::class
    );


    /*
    |--------------------------------------------------------------------------
    | Sample Management / Laboratory Results Routes
    |--------------------------------------------------------------------------
    | Supports supervisor requirements:
    | - Sample code
    | - Collection date
    | - Collector
    | - Location
    | - Linked project
    | - Sample information
    | - Test results
    | - Result documents
    |
    | Important:
    | Summary, restore, update-with-files, result, and document routes must be
    | declared before apiResource. Otherwise, Laravel may treat route keywords
    | as sample IDs.
    */
    Route::get(
        'samples-laboratory/summary',
        [SampleLaboratoryController::class, 'summary']
    )->name('samples-laboratory.summary');

    Route::post(
        'samples-laboratory/{id}/restore',
        [SampleLaboratoryController::class, 'restore']
    )->name('samples-laboratory.restore');

    /*
    |--------------------------------------------------------------------------
    | Update sample with result documents
    |--------------------------------------------------------------------------
    | Use this from frontend when uploading files:
    | POST /api/samples-laboratory/{id}
    | _method=PUT
    | result_documents[]=file.pdf
    */
    Route::post(
        'samples-laboratory/{id}',
        [SampleLaboratoryController::class, 'update']
    )->name('samples-laboratory.update-with-files');

    /*
    |--------------------------------------------------------------------------
    | Laboratory Result Routes
    |--------------------------------------------------------------------------
    */
    Route::post(
        'samples-laboratory/{sampleId}/laboratory-results',
        [SampleLaboratoryController::class, 'storeLaboratoryResult']
    )->name('samples-laboratory.results.store');

    Route::put(
        'samples-laboratory/{sampleId}/laboratory-results/{resultId}',
        [SampleLaboratoryController::class, 'updateLaboratoryResult']
    )->name('samples-laboratory.results.update');

    Route::patch(
        'samples-laboratory/{sampleId}/laboratory-results/{resultId}',
        [SampleLaboratoryController::class, 'updateLaboratoryResult']
    )->name('samples-laboratory.results.patch');

    Route::post(
        'samples-laboratory/{sampleId}/laboratory-results/{resultId}',
        [SampleLaboratoryController::class, 'updateLaboratoryResult']
    )->name('samples-laboratory.results.update-with-files');

    Route::delete(
        'samples-laboratory/{sampleId}/laboratory-results/{resultId}',
        [SampleLaboratoryController::class, 'deleteLaboratoryResult']
    )->name('samples-laboratory.results.delete');

    Route::delete(
        'samples-laboratory/{sampleId}/laboratory-results/{resultId}/documents/{documentId}',
        [SampleLaboratoryController::class, 'deleteResultDocument']
    )->name('samples-laboratory.results.documents.delete');

    Route::apiResource(
        'samples-laboratory',
        SampleLaboratoryController::class
    );

    /*
    |--------------------------------------------------------------------------
    | Document Upload / Metadata Routes
    |--------------------------------------------------------------------------
    | Existing document upload, listing, update and deletion routes.
    */
    Route::apiResource(
        'documents',
        DocumentController::class
    );

    /*
    |--------------------------------------------------------------------------
    | Document Archive Routes
    |--------------------------------------------------------------------------
    | Archive is a lifecycle state for clean documents that are no longer active.
    | It keeps the file and database record for audit, but separates old records
    | from normal daily document work.
    */
    Route::prefix('document-archives')
        ->controller(DocumentArchiveController::class)
        ->group(function () {
            Route::get(
                'summary',
                'archiveSummary'
            );

            Route::get(
                'documents',
                'archivedDocuments'
            );

            Route::post(
                'documents/{id}/archive',
                'archiveDocument'
            );

            Route::post(
                'documents/{id}/restore',
                'restoreDocument'
            );

            Route::get(
                'logs',
                'archiveLogs'
            );
        });

    /*
    |--------------------------------------------------------------------------
    | Geological Record Routes
    |--------------------------------------------------------------------------
    | The summary route must be declared before the API resource route.
    | Otherwise, Laravel may treat "summary" as a geological record ID.
    |
    | Geological records are linked to existing documents and add structured
    | geological metadata without changing the current document workflow.
    */
    Route::get(
        'geological-records/summary',
        [GeologicalRecordController::class, 'summary']
    );

    Route::apiResource(
        'geological-records',
        GeologicalRecordController::class
    );

    /*
    |--------------------------------------------------------------------------
    | Document Security / Antivirus Routes
    |--------------------------------------------------------------------------
    */
    Route::prefix('document-security')
        ->controller(DocumentSecurityController::class)
        ->group(function () {
            Route::post(
                'documents/{id}/scan',
                'scanDocument'
            );

            Route::post(
                'scan-pending',
                'scanPendingDocuments'
            );

            Route::get(
                'quarantine',
                'quarantinedDocuments'
            );

            Route::post(
                'quarantine/{id}/reject',
                'rejectQuarantinedDocument'
            );

            Route::get(
                'scan-logs',
                'scanLogs'
            );
        });

    /*
    |--------------------------------------------------------------------------
    | Secure Document Access Routes
    |--------------------------------------------------------------------------
    */
    Route::prefix('document-access')
        ->controller(DocumentAccessController::class)
        ->group(function () {
            Route::get(
                'documents/{id}/status',
                'accessStatus'
            );

            Route::get(
                'documents/{id}/view',
                'view'
            );

            Route::get(
                'documents/{id}/download',
                'download'
            );
        });

    /*
    |--------------------------------------------------------------------------
    | Document Cryptography / Encryption Routes
    |--------------------------------------------------------------------------
    */
    Route::prefix('document-encryption')
        ->controller(DocumentEncryptionController::class)
        ->group(function () {
            Route::get(
                'summary',
                'encryptionSummary'
            );

            Route::post(
                'documents/{id}/encrypt',
                'encryptDocument'
            );

            Route::post(
                'encrypt-clean',
                'encryptCleanDocuments'
            );

            Route::post(
                'documents/{id}/verify',
                'verifyEncryptedDocument'
            );

            Route::get(
                'logs',
                'encryptionLogs'
            );
        });

    /*
    |--------------------------------------------------------------------------
    | Document Plaintext Extraction / OCR Routes
    |--------------------------------------------------------------------------
    */
    Route::prefix('document-plaintext')
        ->controller(DocumentPlaintextController::class)
        ->group(function () {
            Route::get(
                'summary',
                'plaintextSummary'
            );

            Route::post(
                'documents/{id}/extract',
                'extractDocument'
            );

            Route::post(
                'extract-pending',
                'extractPendingDocuments'
            );

            Route::get(
                'documents/{id}',
                'showPlaintext'
            );

            Route::get(
                'search',
                'searchPlaintext'
            );

            Route::get(
                'logs',
                'plaintextLogs'
            );
        });

    /*
    |--------------------------------------------------------------------------
    | Document Sandbox Routes
    |--------------------------------------------------------------------------
    */
    Route::prefix('document-sandbox')
        ->controller(DocumentSandboxController::class)
        ->group(function () {
            Route::get(
                'summary',
                'sandboxSummary'
            );

            Route::post(
                'documents/{id}/test',
                'testDocument'
            );

            Route::post(
                'test-pending',
                'testPendingDocuments'
            );

            Route::get(
                'unsafe',
                'unsafeDocuments'
            );

            Route::post(
                'unsafe/{id}/reject',
                'rejectUnsafeDocument'
            );

            Route::get(
                'logs',
                'sandboxLogs'
            );
        });

    /*
    |--------------------------------------------------------------------------
    | Document AI Analysis Routes
    |--------------------------------------------------------------------------
    */
    Route::prefix('document-ai')
        ->controller(DocumentAiController::class)
        ->group(function () {
            Route::get(
                'summary',
                'aiSummary'
            );

            Route::post(
                'documents/{id}/analyze',
                'analyzeDocument'
            );

            Route::post(
                'analyze-pending',
                'analyzePendingDocuments'
            );

            Route::get(
                'documents/{id}',
                'showAnalysis'
            );

            Route::get(
                'search',
                'searchAnalysis'
            );

            Route::post(
                'documents/{id}/apply-suggestions',
                'applySuggestions'
            );

            Route::get(
                'logs',
                'aiLogs'
            );
        });
});