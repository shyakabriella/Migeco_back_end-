<?php

use Illuminate\Http\Request;
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
    | This keeps the existing endpoint used by the frontend.
    */
    Route::controller(RegisterController::class)->group(function () {
        Route::get('user', 'me');
        Route::get('me', 'me');
    });

    /*
    |--------------------------------------------------------------------------
    | Authentication / Profile Routes
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
    */
    Route::apiResource('roles', RoleController::class);

    /*
    |--------------------------------------------------------------------------
    | Frontend Compatibility Routes
    |--------------------------------------------------------------------------
    | Some frontend pages call /admin/users, /admin/roles, and
    | /user-management/users. These aliases point to the same controllers.
    */
    Route::prefix('admin')->group(function () {
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

    Route::prefix('user-management')->group(function () {
        Route::controller(RegisterController::class)->group(function () {
            Route::get('users', 'users');
            Route::post('users', 'register');
            Route::get('users/{id}', 'showUser');
            Route::put('users/{id}', 'updateUser');
            Route::patch('users/{id}', 'updateUser');
            Route::delete('users/{id}', 'deleteUser');
        });

        Route::get('roles', [RoleController::class, 'index']);
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
    | Project / Site Routes
    |--------------------------------------------------------------------------
    */
    Route::apiResource(
        'projects',
        ProjectController::class
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