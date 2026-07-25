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
    'ffprobe_binary' => env('ODISSEY_FFPROBE_BINARY', 'ffprobe'),
    'e2e_path' => env('ODISSEY_E2E_PATH') ?: '/var/cache/odissey/e2e',
    'artwork_path' => env('ODISSEY_ARTWORK_PATH') ?: rtrim(env('ODISSEY_DATA_PATH') ?: storage_path('app/odissey'), '/').'/artwork',
    'artwork_max_bytes' => (int) env('ODISSEY_ARTWORK_MAX_BYTES', 10 * 1024 * 1024),
    'caption_path' => env('ODISSEY_CAPTION_PATH') ?: rtrim(env('ODISSEY_DATA_PATH') ?: storage_path('app/odissey'), '/').'/captions',
    'caption_max_bytes' => (int) env('ODISSEY_CAPTION_MAX_BYTES', 20 * 1024 * 1024),
    'caption_languages' => array_values(array_filter(array_map(fn ($language) => strtolower(trim($language)), explode(',', env('ODISSEY_CAPTION_LANGUAGES', 'en'))), fn ($language) => preg_match('/^[a-z]{2,3}$/', $language))),
    'source_catalog_max_bytes' => (int) env('ODISSEY_SOURCE_CATALOG_MAX_BYTES', 64 * 1024 * 1024),
    'remote_transcode_max_source_bytes' => (int) env('ODISSEY_REMOTE_TRANSCODE_MAX_SOURCE_BYTES', 8 * 1024 * 1024 * 1024),
    'local_source_roots' => array_values(array_filter(explode(',', env('ODISSEY_LOCAL_SOURCE_ROOTS', '/media')))),
    'video_extensions' => ['mp4', 'm4v', 'mkv', 'avi', 'mov', 'webm', 'mpeg', 'mpg', 'ts', 'm2ts'],
    'audio_extensions' => ['mp3', 'm4a', 'aac', 'flac', 'ogg', 'opus', 'wav'],
];
