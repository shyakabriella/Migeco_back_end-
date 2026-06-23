<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Local frontend usually runs on Vite:
    | - http://localhost:5173
    | - http://127.0.0.1:5173
    |
    | The backend usually runs on:
    | - http://127.0.0.1:8000
    | - http://localhost:8000
    |
    | We allow both localhost and 127.0.0.1 because the browser treats them as
    | different origins.
    |
    */

    'paths' => [
        'api/*',
        'sanctum/csrf-cookie',
    ],

    'allowed_methods' => [
        '*',
    ],

    'allowed_origins' => [
        'http://localhost:5173',
        'http://127.0.0.1:5173',
        'http://localhost:3000',
        'http://127.0.0.1:3000',
        'https://www.migeco.asyncafrica.com',
        'https://migeco.asyncafrica.com',
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => [
        '*',
    ],

    'exposed_headers' => [],

    'max_age' => 0,

    /*
    |--------------------------------------------------------------------------
    | Credentials
    |--------------------------------------------------------------------------
    |
    | For this project you are using Sanctum Bearer tokens in the Authorization
    | header, not SPA cookie authentication. Keeping this false makes local CORS
    | simpler and avoids credential-origin restrictions.
    |
    */

    'supports_credentials' => false,

];