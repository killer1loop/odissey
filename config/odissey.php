<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Persistent application data
    |--------------------------------------------------------------------------
    |
    | Odissey persists metadata, user state, and encrypted source settings.
    | Source media is always referenced in place and is never ingested.
    |
    */
    'data_path' => env('ODISSEY_DATA_PATH') ?: storage_path('app/odissey'),

    /*
    |--------------------------------------------------------------------------
    | Transient streaming data
    |--------------------------------------------------------------------------
    |
    | FFmpeg may create short-lived HLS manifests and segments while a stream
    | is active. Deployments should place this path on local ephemeral storage
    | or tmpfs and enforce both a time-to-live and a size limit.
    |
    */
    'transcode_path' => env('ODISSEY_TRANSCODE_PATH') ?: storage_path('app/transcodes'),
    'max_transcodes' => (int) env('ODISSEY_MAX_TRANSCODES', 1),
    'transcode_ttl_minutes' => (int) env('ODISSEY_TRANSCODE_TTL_MINUTES', 30),
];
