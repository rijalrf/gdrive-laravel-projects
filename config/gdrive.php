<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Google Drive Credentials Configuration
    |--------------------------------------------------------------------------
    |
    | Here you can configure the credentials for Google Drive. We support
    | both a file path to the credentials JSON, or direct JSON string configuration.
    |
    */
    'credentials' => [
        'mode' => env('GOOGLE_DRIVE_CREDENTIALS_MODE', 'file'), // 'file', 'json', or 'refresh_token'
        'file_path' => env('GOOGLE_DRIVE_CREDENTIALS_PATH', storage_path('app/google-credentials.json')),
        'json_string' => env('GOOGLE_DRIVE_SERVICE_ACCOUNT_JSON', null),
        'client_id' => env('GOOGLE_DRIVE_CLIENT_ID', null),
        'client_secret' => env('GOOGLE_DRIVE_CLIENT_SECRET', null),
        'refresh_token' => env('GOOGLE_DRIVE_REFRESH_TOKEN', null),
    ],

    /*
    |--------------------------------------------------------------------------
    | Google Drive Parent Folder ID
    |--------------------------------------------------------------------------
    |
    | Define the root/parent folder ID where all your uploads will be placed.
    | If null, files will be uploaded directly to the root of your Google Drive.
    |
    */
    'parent_folder_id' => env('GOOGLE_DRIVE_PARENT_FOLDER_ID', null),

    /*
    |--------------------------------------------------------------------------
    | Default Upload Mode
    |--------------------------------------------------------------------------
    |
    | Supported: "sync", "async"
    | - sync: uploads directly on the web request.
    | - async: dispatches to a Laravel queue.
    |
    */
    'upload_mode' => env('GDRIVE_UPLOAD_MODE', 'sync'),

    /*
    |--------------------------------------------------------------------------
    | Image Compression Config
    |--------------------------------------------------------------------------
    |
    | Set default behavior for compressing images before uploading.
    | - enabled: true or false
    | - quality: 1-100 (higher means better quality, lower means smaller file)
    |
    */
    'compress' => [
        'enabled' => env('GDRIVE_COMPRESS_ENABLED', true),
        'quality' => env('GDRIVE_COMPRESS_QUALITY', 75),
    ],
];
