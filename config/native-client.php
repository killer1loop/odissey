<?php

return [
    'api_min_version' => '1',
    'api_max_version' => '1',
    'access_token_minutes' => min(
        60,
        max(5, (int) env('ODISSEY_NATIVE_ACCESS_TOKEN_MINUTES', 15)),
    ),
    'refresh_token_days' => min(
        90,
        max(1, (int) env('ODISSEY_NATIVE_REFRESH_TOKEN_DAYS', 30)),
    ),
    'playback_grant_minutes' => min(
        10,
        max(1, (int) env('ODISSEY_NATIVE_PLAYBACK_GRANT_MINUTES', 10)),
    ),
    'playback_renewal_minutes' => min(
        10,
        max(1, (int) env('ODISSEY_NATIVE_PLAYBACK_RENEWAL_MINUTES', 10)),
    ),
    'maximum_sessions_per_user' => min(
        50,
        max(1, (int) env('ODISSEY_NATIVE_MAXIMUM_SESSIONS_PER_USER', 12)),
    ),
    // Every consumed refresh token remains hashed until its bounded session is
    // pruned. Requiring a fresh login at the ceiling prevents unbounded growth
    // without weakening refresh-token family replay detection.
    'maximum_refresh_rotations_per_session' => min(
        16384,
        max(
            64,
            (int) env(
                'ODISSEY_NATIVE_MAXIMUM_REFRESH_ROTATIONS_PER_SESSION',
                4096,
            ),
        ),
    ),
    'playback_grant_retention_days' => min(
        90,
        max(
            1,
            (int) env(
                'ODISSEY_NATIVE_PLAYBACK_GRANT_RETENTION_DAYS',
                7,
            ),
        ),
    ),
    'session_retention_days' => min(
        365,
        max(
            7,
            (int) env('ODISSEY_NATIVE_SESSION_RETENTION_DAYS', 30),
        ),
    ),
    'admin_audit_retention_days' => min(
        3650,
        max(
            30,
            (int) env('ODISSEY_ADMIN_AUDIT_RETENTION_DAYS', 365),
        ),
    ),
    'maximum_admin_audit_events' => min(
        250000,
        max(
            1000,
            (int) env('ODISSEY_MAXIMUM_ADMIN_AUDIT_EVENTS', 50000),
        ),
    ),
    'maximum_music_playlists_per_user' => min(
        1000,
        max(
            10,
            (int) env('ODISSEY_MAXIMUM_MUSIC_PLAYLISTS_PER_USER', 250),
        ),
    ),
    'maximum_music_playlist_tracks' => min(
        1000,
        max(
            100,
            (int) env('ODISSEY_MAXIMUM_MUSIC_PLAYLIST_TRACKS', 1000),
        ),
    ),
    'music_playlist_lock_seconds' => min(
        120,
        max(
            10,
            (int) env('ODISSEY_MUSIC_PLAYLIST_LOCK_SECONDS', 30),
        ),
    ),
    'music_playlist_lock_wait_seconds' => min(
        5,
        max(
            0,
            (int) env('ODISSEY_MUSIC_PLAYLIST_LOCK_WAIT_SECONDS', 2),
        ),
    ),
    'prune_batch_size' => min(
        10000,
        max(
            100,
            (int) env('ODISSEY_NATIVE_PRUNE_BATCH_SIZE', 2000),
        ),
    ),
];
