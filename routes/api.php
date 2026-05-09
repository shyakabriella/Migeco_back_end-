<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\API\RegisterController;
use App\Http\Controllers\API\DocumentCategoryController;
use App\Http\Controllers\API\ProjectController;
use App\Http\Controllers\API\DocumentController;
use App\Http\Controllers\API\DocumentSecurityController;
use App\Http\Controllers\API\DocumentAccessController;
use App\Http\Controllers\API\DocumentEncryptionController;
use App\Http\Controllers\API\DocumentPlaintextController;
use App\Http\Controllers\API\DocumentSandboxController;
use App\Http\Controllers\API\DocumentAiController;

/*
|--------------------------------------------------------------------------
| Public API Routes
|--------------------------------------------------------------------------
| Only login is public.
| Register is protected because only Admin can create users.
*/
Route::controller(RegisterController::class)->group(function () {
    Route::post('/login', 'login');
});

/*
|--------------------------------------------------------------------------
| Protected API Routes
|--------------------------------------------------------------------------
| All routes here require Sanctum Bearer Token.
*/
Route::middleware('auth:sanctum')->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Authenticated User
    |--------------------------------------------------------------------------
    */
    Route::get('/user', function (Request $request) {
        return $request->user()->load('role');
    });

    /*
    |--------------------------------------------------------------------------
    | Authentication / Profile Routes
    |--------------------------------------------------------------------------
    */
    Route::controller(RegisterController::class)->group(function () {
        Route::post('/register', 'register');
        Route::post('/logout', 'logout');
        Route::get('/profile', 'profile');
    });

    /*
    |--------------------------------------------------------------------------
    | Document Category Routes
    |--------------------------------------------------------------------------
    */
    Route::apiResource('/document-categories', DocumentCategoryController::class);

    /*
    |--------------------------------------------------------------------------
    | Project / Site Routes
    |--------------------------------------------------------------------------
    */
    Route::apiResource('/projects', ProjectController::class);

    /*
    |--------------------------------------------------------------------------
    | Document Upload / Metadata Routes
    |--------------------------------------------------------------------------
    */
    Route::apiResource('/documents', DocumentController::class);

    /*
    |--------------------------------------------------------------------------
    | Document Security / Antivirus Routes
    |--------------------------------------------------------------------------
    */
    Route::prefix('document-security')
        ->controller(DocumentSecurityController::class)
        ->group(function () {
            Route::post('/documents/{id}/scan', 'scanDocument');
            Route::post('/scan-pending', 'scanPendingDocuments');
            Route::get('/quarantine', 'quarantinedDocuments');
            Route::post('/quarantine/{id}/reject', 'rejectQuarantinedDocument');
            Route::get('/scan-logs', 'scanLogs');
        });

    /*
    |--------------------------------------------------------------------------
    | Secure Document Access Routes
    |--------------------------------------------------------------------------
    */
    Route::prefix('document-access')
        ->controller(DocumentAccessController::class)
        ->group(function () {
            Route::get('/documents/{id}/status', 'accessStatus');
            Route::get('/documents/{id}/view', 'view');
            Route::get('/documents/{id}/download', 'download');
        });

    /*
    |--------------------------------------------------------------------------
    | Document Cryptography / Encryption Routes
    |--------------------------------------------------------------------------
    */
    Route::prefix('document-encryption')
        ->controller(DocumentEncryptionController::class)
        ->group(function () {
            Route::get('/summary', 'encryptionSummary');
            Route::post('/documents/{id}/encrypt', 'encryptDocument');
            Route::post('/encrypt-clean', 'encryptCleanDocuments');
            Route::post('/documents/{id}/verify', 'verifyEncryptedDocument');
            Route::get('/logs', 'encryptionLogs');
        });

    /*
    |--------------------------------------------------------------------------
    | Document Plaintext Extraction / OCR Routes
    |--------------------------------------------------------------------------
    */
    Route::prefix('document-plaintext')
        ->controller(DocumentPlaintextController::class)
        ->group(function () {
            Route::get('/summary', 'plaintextSummary');
            Route::post('/documents/{id}/extract', 'extractDocument');
            Route::post('/extract-pending', 'extractPendingDocuments');
            Route::get('/documents/{id}', 'showPlaintext');
            Route::get('/search', 'searchPlaintext');
            Route::get('/logs', 'plaintextLogs');
        });

    /*
    |--------------------------------------------------------------------------
    | Document Sandbox Routes
    |--------------------------------------------------------------------------
    */
    Route::prefix('document-sandbox')
        ->controller(DocumentSandboxController::class)
        ->group(function () {
            Route::get('/summary', 'sandboxSummary');
            Route::post('/documents/{id}/test', 'testDocument');
            Route::post('/test-pending', 'testPendingDocuments');
            Route::get('/unsafe', 'unsafeDocuments');
            Route::post('/unsafe/{id}/reject', 'rejectUnsafeDocument');
            Route::get('/logs', 'sandboxLogs');
        });

    /*
    |--------------------------------------------------------------------------
    | Document AI Analysis Routes
    |--------------------------------------------------------------------------
    */
    Route::prefix('document-ai')
        ->controller(DocumentAiController::class)
        ->group(function () {
            Route::get('/summary', 'aiSummary');
            Route::post('/documents/{id}/analyze', 'analyzeDocument');
            Route::post('/analyze-pending', 'analyzePendingDocuments');
            Route::get('/documents/{id}', 'showAnalysis');
            Route::get('/search', 'searchAnalysis');
            Route::post('/documents/{id}/apply-suggestions', 'applySuggestions');
            Route::get('/logs', 'aiLogs');
        });
});