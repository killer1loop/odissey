<?php

return [
    'playlist_max_bytes' => (int) env('IPTV_PLAYLIST_MAX_BYTES', 32 * 1024 * 1024),
    'playlist_max_channels' => (int) env('IPTV_PLAYLIST_MAX_CHANNELS', 50000),
    'xmltv_max_bytes' => (int) env('IPTV_XMLTV_MAX_BYTES', 128 * 1024 * 1024),
    'connect_timeout_seconds' => (int) env('IPTV_CONNECT_TIMEOUT_SECONDS', 5),
    'request_timeout_seconds' => (int) env('IPTV_REQUEST_TIMEOUT_SECONDS', 20),
    'stream_timeout_seconds' => (int) env('IPTV_STREAM_TIMEOUT_SECONDS', 45),
    'manifest_max_bytes' => (int) env('IPTV_MANIFEST_MAX_BYTES', 2 * 1024 * 1024),
    'resource_url_max_bytes' => (int) env('IPTV_RESOURCE_URL_MAX_BYTES', 8192),
    'manifest_max_resources' => (int) env('IPTV_MANIFEST_MAX_RESOURCES', 256),
    'playback_max_resources' => (int) env('IPTV_PLAYBACK_MAX_RESOURCES', 2048),
    'playback_max_attempts' => (int) env('IPTV_PLAYBACK_MAX_ATTEMPTS', 100),
    'playback_max_redirects' => (int) env('IPTV_PLAYBACK_MAX_REDIRECTS', 2),
    'playlist_max_depth' => (int) env('IPTV_PLAYLIST_MAX_DEPTH', 6),
    'api_max_response_bytes' => (int) env('IPTV_API_MAX_RESPONSE_BYTES', 64 * 1024 * 1024),
    'category_max_rows' => (int) env('IPTV_CATEGORY_MAX_ROWS', 5000),
    'channel_max_rows' => (int) env('IPTV_CHANNEL_MAX_ROWS', 100000),
    'guide_channel_limit' => (int) env('IPTV_GUIDE_CHANNEL_LIMIT', 20),
    'guide_program_limit' => (int) env('IPTV_GUIDE_PROGRAM_LIMIT', 4),
    'playback_session_minutes' => (int) env('IPTV_PLAYBACK_SESSION_MINUTES', 30),
    'provider_max_connections' => (int) env('IPTV_PROVIDER_MAX_CONNECTIONS', 1),
];
