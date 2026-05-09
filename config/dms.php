<?php

return [

    /*
    |--------------------------------------------------------------------------
    | DMS Antivirus Settings
    |--------------------------------------------------------------------------
    */

    'antivirus' => [
        'enabled' => env('DMS_ANTIVIRUS_ENABLED', true),
        'clamscan_path' => env('DMS_CLAMSCAN_PATH', 'clamscan'),
        'timeout' => env('DMS_SCAN_TIMEOUT', 120),
    ],

    /*
    |--------------------------------------------------------------------------
    | DMS Encryption Settings
    |--------------------------------------------------------------------------
    */

    'encryption' => [
        'enabled' => env('DMS_ENCRYPTION_ENABLED', true),
        'key' => env('DMS_ENCRYPTION_KEY'),
        'key_id' => env('DMS_ENCRYPTION_KEY_ID', 'main-key-2026'),
        'algorithm' => 'XCHACHA20-POLY1305-SECRETSTREAM',
        'delete_plain_file_after_encrypt' => env('DMS_DELETE_PLAIN_FILE_AFTER_ENCRYPT', true),
        'chunk_size' => 8192,
    ],

    /*
    |--------------------------------------------------------------------------
    | DMS Plaintext Extraction Settings
    |--------------------------------------------------------------------------
    */

    'plaintext' => [
        'enabled' => env('DMS_PLAINTEXT_ENABLED', true),
        'save_plaintext_file' => env('DMS_SAVE_PLAINTEXT_FILE', true),
        'save_content_to_database' => env('DMS_SAVE_PLAINTEXT_TO_DATABASE', true),
        'max_database_characters' => env('DMS_MAX_PLAINTEXT_DB_CHARS', 500000),
        'preview_length' => 500,
    ],

    /*
    |--------------------------------------------------------------------------
    | DMS Sandbox Settings
    |--------------------------------------------------------------------------
    | This sandbox does static inspection only.
    | It does not execute files.
    |--------------------------------------------------------------------------
    */

    'sandbox' => [
        'enabled' => env('DMS_SANDBOX_ENABLED', true),

        /*
        |--------------------------------------------------------------------------
        | Risk Score
        |--------------------------------------------------------------------------
        | If score is equal or above this value, document becomes unsafe.
        */
        'unsafe_score' => env('DMS_SANDBOX_UNSAFE_SCORE', 50),

        /*
        |--------------------------------------------------------------------------
        | Maximum Bytes to Inspect
        |--------------------------------------------------------------------------
        | Used for large binary files.
        */
        'max_bytes_to_inspect' => env('DMS_SANDBOX_MAX_BYTES', 2097152), // 2MB

        /*
        |--------------------------------------------------------------------------
        | Block Access Until Sandbox Is Safe
        |--------------------------------------------------------------------------
        | true = document cannot be viewed/downloaded until sandbox_status = safe.
        */
        'require_safe_sandbox_for_access' => env('DMS_REQUIRE_SAFE_SANDBOX', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | DMS AI Settings
    |--------------------------------------------------------------------------
    | local = built-in rule-based analyzer
    | external = connect your own AI endpoint later
    |--------------------------------------------------------------------------
    */

    'ai' => [
        'enabled' => env('DMS_AI_ENABLED', true),

        /*
        |--------------------------------------------------------------------------
        | AI Provider
        |--------------------------------------------------------------------------
        | local    = built-in local rule-based analyzer
        | external = call external AI API endpoint
        */
        'provider' => env('DMS_AI_PROVIDER', 'local'),

        /*
        |--------------------------------------------------------------------------
        | AI Model Name
        |--------------------------------------------------------------------------
        */
        'model' => env('DMS_AI_MODEL', 'dms-local-analyzer-v1'),

        /*
        |--------------------------------------------------------------------------
        | External AI API
        |--------------------------------------------------------------------------
        | Used only when DMS_AI_PROVIDER=external.
        */
        'endpoint' => env('DMS_AI_ENDPOINT'),
        'api_key' => env('DMS_AI_API_KEY'),

        /*
        |--------------------------------------------------------------------------
        | Input Limit
        |--------------------------------------------------------------------------
        */
        'max_input_characters' => env('DMS_AI_MAX_INPUT_CHARS', 20000),

        /*
        |--------------------------------------------------------------------------
        | Timeout
        |--------------------------------------------------------------------------
        */
        'timeout' => env('DMS_AI_TIMEOUT', 60),

        /*
        |--------------------------------------------------------------------------
        | Fallback
        |--------------------------------------------------------------------------
        | If external AI fails, use local analyzer.
        */
        'fallback_to_local' => env('DMS_AI_FALLBACK_TO_LOCAL', true),
    ],

];