<?php

return [
    'release' => env('ODISSEY_RELEASE', 'development'),
    'runtime_cache_store' => env('ODISSEY_RUNTIME_CACHE_STORE', 'file'),

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
    'backup_max_database_bytes' => (int) env('ODISSEY_BACKUP_MAX_DATABASE_BYTES', 10 * 1024 * 1024 * 1024),

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
    'transcode_min_free_bytes' => (int) env('ODISSEY_TRANSCODE_MIN_FREE_BYTES', 256 * 1024 * 1024),
    'max_pending_transcodes_per_user' => (int) env('ODISSEY_MAX_PENDING_TRANSCODES_PER_USER', 3),
    'max_pending_transcodes' => (int) env('ODISSEY_MAX_PENDING_TRANSCODES', 50),
    'playback_history_aggregation_seconds' => (int) env('ODISSEY_PLAYBACK_HISTORY_AGGREGATION_SECONDS', 60),
    'playback_history_retention_days' => (int) env('ODISSEY_PLAYBACK_HISTORY_RETENTION_DAYS', 365),

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
    'ffmpeg_threads' => (int) env('ODISSEY_FFMPEG_THREADS', 2),
    'ffmpeg_max_alloc_bytes' => (int) env('ODISSEY_FFMPEG_MAX_ALLOC_BYTES', 256 * 1024 * 1024),
    'ffmpeg_max_pixels' => (int) env('ODISSEY_FFMPEG_MAX_PIXELS', 7680 * 4320),
    'ffmpeg_max_video_bitrate_kbps' => (int) env('ODISSEY_FFMPEG_MAX_VIDEO_BITRATE_KBPS', 10000),
    'ffprobe_max_output_bytes' => (int) env('ODISSEY_FFPROBE_MAX_OUTPUT_BYTES', 4 * 1024 * 1024),
    'embedded_subtitle_timeout_seconds' => (int) env('ODISSEY_EMBEDDED_SUBTITLE_TIMEOUT_SECONDS', 120),
    'embedded_subtitle_max_bytes' => (int) env('ODISSEY_EMBEDDED_SUBTITLE_MAX_BYTES', 20 * 1024 * 1024),
    'embedded_subtitle_cache_minutes' => (int) env('ODISSEY_EMBEDDED_SUBTITLE_CACHE_MINUTES', 1440),
    'e2e_path' => env('ODISSEY_E2E_PATH') ?: '/var/cache/odissey/e2e',
    'artwork_path' => env('ODISSEY_ARTWORK_PATH') ?: rtrim(env('ODISSEY_DATA_PATH') ?: storage_path('app/odissey'), '/').'/artwork',
    'artwork_max_bytes' => (int) env('ODISSEY_ARTWORK_MAX_BYTES', 10 * 1024 * 1024),
    'caption_path' => env('ODISSEY_CAPTION_PATH') ?: rtrim(env('ODISSEY_DATA_PATH') ?: storage_path('app/odissey'), '/').'/captions',
    'caption_max_bytes' => (int) env('ODISSEY_CAPTION_MAX_BYTES', 8 * 1024 * 1024),
    'caption_max_tracks_per_item' => (int) env('ODISSEY_CAPTION_MAX_TRACKS_PER_ITEM', 20),
    'provider_json_max_bytes' => (int) env('ODISSEY_PROVIDER_JSON_MAX_BYTES', 4 * 1024 * 1024),
    'caption_auto_fetch_max_items_per_scan' => (int) env('ODISSEY_CAPTION_AUTO_FETCH_MAX_ITEMS_PER_SCAN', 250),
    'media_asset_max_bytes' => (int) env('ODISSEY_MEDIA_ASSET_MAX_BYTES', 10 * 1024 * 1024 * 1024),
    'media_asset_min_free_bytes' => (int) env('ODISSEY_MEDIA_ASSET_MIN_FREE_BYTES', 256 * 1024 * 1024),
    'caption_languages' => array_values(array_filter(array_map(fn ($language) => strtolower(trim($language)), explode(',', env('ODISSEY_CAPTION_LANGUAGES', 'en'))), fn ($language) => preg_match('/^[a-z]{2,3}$/', $language))),
    'source_catalog_max_bytes' => (int) env('ODISSEY_SOURCE_CATALOG_MAX_BYTES', 4 * 1024 * 1024),
    'source_catalog_max_items' => (int) env('ODISSEY_SOURCE_CATALOG_MAX_ITEMS', 100000),
    'source_catalog_max_requests' => (int) env('ODISSEY_SOURCE_CATALOG_MAX_REQUESTS', 10000),
    'source_catalog_max_s3_pages' => (int) env('ODISSEY_SOURCE_CATALOG_MAX_S3_PAGES', 250),
    'source_catalog_max_locator_bytes' => (int) env('ODISSEY_SOURCE_CATALOG_MAX_LOCATOR_BYTES', 4096),
    'source_catalog_timeout_seconds' => (int) env('ODISSEY_SOURCE_CATALOG_TIMEOUT_SECONDS', 300),
    'remote_transcode_max_source_bytes' => (int) env('ODISSEY_REMOTE_TRANSCODE_MAX_SOURCE_BYTES', 3 * 1024 * 1024 * 1024),
    'remote_stream_max_bytes' => (int) env('ODISSEY_REMOTE_STREAM_MAX_BYTES', 32 * 1024 * 1024 * 1024),
    'remote_stream_max_seconds' => (int) env('ODISSEY_REMOTE_STREAM_MAX_SECONDS', 900),
    'remote_stream_lease_seconds' => (int) env('ODISSEY_REMOTE_STREAM_LEASE_SECONDS', 915),
    'remote_stream_user_concurrency' => (int) env('ODISSEY_REMOTE_STREAM_USER_CONCURRENCY', 4),
    'remote_stream_source_concurrency' => (int) env('ODISSEY_REMOTE_STREAM_SOURCE_CONCURRENCY', 12),
    'remote_stream_global_concurrency' => (int) env('ODISSEY_REMOTE_STREAM_GLOBAL_CONCURRENCY', 32),
    'local_source_roots' => array_values(array_filter(explode(',', env('ODISSEY_LOCAL_SOURCE_ROOTS', '/media')))),
    'video_extensions' => ['mp4', 'm4v', 'mkv', 'avi', 'mov', 'webm', 'mpeg', 'mpg', 'ts', 'm2ts'],
    'audio_extensions' => ['mp3', 'm4a', 'aac', 'flac', 'ogg', 'opus', 'wav'],
];
