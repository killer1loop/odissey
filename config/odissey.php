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
    'transcode_path' => env('ODISSEY_TRANSCODE_PATH') ?: '/var/cache/odissey/transcodes',
    'max_transcodes' => (int) env('ODISSEY_MAX_TRANSCODES', 1),
    'transcode_ttl_minutes' => (int) env('ODISSEY_TRANSCODE_TTL_MINUTES', 30),
    'transcode_max_bytes' => (int) env('ODISSEY_TRANSCODE_MAX_BYTES', 5 * 1024 * 1024 * 1024),
    'transcode_failed_retention_minutes' => (int) env('ODISSEY_TRANSCODE_FAILED_RETENTION_MINUTES', 60),
    'transcode_stale_minutes' => (int) env('ODISSEY_TRANSCODE_STALE_MINUTES', 15),
    'transcode_capacity_retry_seconds' => (int) env('ODISSEY_TRANSCODE_CAPACITY_RETRY_SECONDS', 5),

    /*
    |--------------------------------------------------------------------------
    | Media tooling
    |--------------------------------------------------------------------------
    |
    | E2E media is generated only by the explicit fixture command and lives
    | outside persistent application data. Process arguments are always passed
    | directly to Symfony Process; a shell is never invoked.
    |
    */
    'ffmpeg_binary' => env('ODISSEY_FFMPEG_BINARY', 'ffmpeg'),
    'e2e_path' => env('ODISSEY_E2E_PATH') ?: '/var/cache/odissey/e2e',
];
