@extends('layouts.app')

@php
    $isVideo = $item->media_kind === 'video';
    $isEpisode = ($item->metadata['kind'] ?? '') === 'episode';
    $libraryLabel = $isEpisode ? 'TV Shows' : 'Movies';
    $libraryUrl = route('media.index', array_filter([
        'kind' => $item->media_kind,
        'library' => $isEpisode ? 'tv' : ($isVideo ? 'movies' : null),
        'series' => $item->metadata['series_title'] ?? null,
        'source' => $item->media_source_id,
    ]));
    $sourceType = $item->requires_transcode ? 'hls' : 'direct';
    $sourceUrl = $item->requires_transcode
        ? ($session?->isAvailable() ? route('media.transcodes.manifest', [$item, $session]) : '')
        : route('media.direct', $item);
    $technical = $item->metadata['technical'] ?? [];
    $subtitleCount = count($technical['subtitle_tracks'] ?? []) + $item->subtitles->count();
    $averageBitrate = $item->duration_ms > 0 && $item->size_bytes > 0
        ? (int) round(($item->size_bytes * 8) / ($item->duration_ms / 1000))
        : null;
    $formatRemaining = static function (?int $milliseconds): string {
        if ($milliseconds === null) {
            return 'Time unavailable';
        }

        $minutes = (int) ceil($milliseconds / 60000);

        if ($minutes < 60) {
            return $minutes.'m remaining';
        }

        $hours = intdiv($minutes, 60);
        $remainingMinutes = $minutes % 60;

        return $hours.'h'.($remainingMinutes > 0 ? ' '.$remainingMinutes.'m' : '').' remaining';
    };
@endphp

@section('title', $item->title.' · Odissey')
@section('body-class', $isVideo ? 'player-page' : '')

@if($isVideo)
    @section('topbar')
        <div class="player-topbar-copy">
            @if (($itemArtwork->metadata['poster_cached'] ?? false) || ($itemArtwork->metadata['poster_url'] ?? false))
                <img
                    class="player-media-poster"
                    src="{{ route('media.artwork', [$itemArtwork, 'poster']) }}"
                    alt=""
                    width="36"
                    height="36"
                >
            @else
                <span class="player-media-poster player-media-poster-fallback" aria-hidden="true">
                    {{ mb_strtoupper(mb_substr($item->title, 0, 1)) }}
                </span>
            @endif
            <div>
                <p>
                    {{ $isEpisode ? ($item->metadata['series_title'] ?? 'TV episode') : 'Movie' }}
                    @if($isEpisode && ($item->metadata['season_number'] ?? null))
                        · S{{ str_pad((string) $item->metadata['season_number'], 2, '0', STR_PAD_LEFT) }}E{{ str_pad((string) ($item->metadata['episode_number'] ?? 0), 2, '0', STR_PAD_LEFT) }}
                    @endif
                </p>
                <h1>{{ $item->title }}</h1>
            </div>
            @if($item->metadata['year'] ?? null)
                <span class="player-program">
                    {{ $item->metadata['year'] }}
                    @if($item->metadata['genres'] ?? null) · {{ implode(' · ', array_slice($item->metadata['genres'], 0, 2)) }} @endif
                </span>
            @endif
        </div>
        <a class="icon-button square-button" href="{{ $libraryUrl }}" aria-label="Close player and return to {{ $libraryLabel }}">
            <svg aria-hidden="true" viewBox="0 0 24 24">
                <path d="m6 6 12 12M18 6 6 18"/>
            </svg>
        </a>
    @endsection
@endif

@section('content')
    @if($isVideo)
        <section
            class="live-player-shell media-player-shell"
            data-media-player
            data-source-type="{{ $sourceType }}"
            data-source-url="{{ $sourceUrl }}"
            data-progress-url="{{ route('media.progress', $item) }}"
            data-progress-sequence="{{ $progress?->sequence ?? 0 }}"
            data-resume-seconds="{{ floor(($progress?->position_ms ?? 0) / 1000) }}"
            data-rail-open="false"
            data-player-state="connecting"
            data-subtitle-count="{{ $subtitleCount }}"
            tabindex="0"
            aria-label="{{ $item->title }} video player"
        >
            <div class="live-player-viewport">
                <div class="live-video-stage">
                    <canvas
                        class="live-ambient-light"
                        data-player-ambient
                        width="48"
                        height="27"
                        aria-hidden="true"
                    ></canvas>

                    <div class="media-video-mount" data-media-video-mount>
                        @if($item->requires_transcode)
                            @include('media.partials.transcode-status', [
                                'item' => $item,
                                'session' => $session,
                                'progress' => $progress,
                            ])
                        @else
                            @include('media.partials.player', [
                                'item' => $item,
                                'progress' => $progress,
                                'sourceType' => 'direct',
                                'sourceUrl' => route('media.direct', $item),
                            ])
                        @endif
                    </div>

                    @if(! $item->requires_transcode || $session?->isAvailable())
                        <div class="live-video-message" data-player-message role="status" aria-live="polite">
                            Preparing playback…
                        </div>
                    @endif

                    <button
                        class="player-rail-trigger"
                        type="button"
                        data-player-rail-toggle
                        aria-controls="media-history-rail"
                        aria-expanded="false"
                    >
                        <svg aria-hidden="true" viewBox="0 0 24 24">
                            <circle cx="12" cy="12" r="9"/>
                            <path d="M12 7v5l3.5 2"/>
                        </svg>
                        <span>History</span>
                    </button>
                </div>

                <aside
                    id="media-history-rail"
                    class="player-channel-rail media-history-rail"
                    data-player-history-rail
                    aria-label="Recent viewing history"
                >
                    <header class="player-rail-header">
                        <div>
                            <p>Your viewing history</p>
                            <h2>Continue watching</h2>
                        </div>
                        <button
                            class="player-rail-close"
                            type="button"
                            data-player-rail-close
                            aria-label="Close viewing history"
                        >
                            <svg aria-hidden="true" viewBox="0 0 24 24">
                                <path d="m6 6 12 12M18 6 6 18"/>
                            </svg>
                        </button>
                    </header>

                    <div class="player-rail-hint" aria-hidden="true">
                        <span><kbd>↑</kbd><kbd>↓</kbd> browse</span>
                        <span><kbd>Enter</kbd> play</span>
                        <span><kbd>F</kbd> full screen</span>
                        <span><kbd>Esc</kbd> exit</span>
                    </div>

                    <div class="player-channel-list" data-player-history-list>
                        @forelse($recentHistory as $historyEntry)
                            @php($historyItem = $historyEntry['item'])
                            @php($historyArtwork = $historyEntry['artwork_item'])
                            <a
                                class="player-channel-item media-history-item {{ $historyEntry['is_current'] ? 'is-active' : '' }}"
                                href="{{ route('media.show', $historyItem) }}"
                                data-player-history-item
                                @if($historyEntry['is_current']) data-history-current aria-current="page" @endif
                            >
                                @if (($historyArtwork->metadata['poster_cached'] ?? false) || ($historyArtwork->metadata['poster_url'] ?? false))
                                    <img
                                        class="player-rail-poster"
                                        src="{{ route('media.artwork', [$historyArtwork, 'poster']) }}"
                                        alt=""
                                        loading="lazy"
                                    >
                                @else
                                    <span class="player-rail-poster player-media-poster-fallback" aria-hidden="true">
                                        {{ mb_strtoupper(mb_substr($historyItem->title, 0, 1)) }}
                                    </span>
                                @endif
                                <span class="player-rail-copy">
                                    <strong>{{ $historyItem->title }}</strong>
                                    <small>
                                        {{ ($historyItem->metadata['kind'] ?? '') === 'episode'
                                            ? ($historyItem->metadata['series_title'] ?? 'TV episode')
                                            : 'Movie' }}
                                    </small>
                                    <span class="history-progress-copy" data-history-time>
                                        {{ $historyEntry['progress_percent'] }}% watched · {{ $formatRemaining($historyEntry['remaining_ms']) }}
                                    </span>
                                    <span
                                        class="history-progress-track"
                                        role="progressbar"
                                        aria-label="Playback progress"
                                        aria-valuemin="0"
                                        aria-valuemax="100"
                                        aria-valuenow="{{ $historyEntry['progress_percent'] }}"
                                    >
                                        <span
                                            data-history-progress
                                            style="--history-progress: {{ $historyEntry['progress_percent'] }}%"
                                        ></span>
                                    </span>
                                </span>
                                @if($historyEntry['is_current'])
                                    <span class="player-channel-playing">Playing</span>
                                @endif
                            </a>
                        @empty
                            <div class="player-rail-empty">
                                <strong>No viewing history yet</strong>
                                <p>Movies and episodes you play will appear here.</p>
                            </div>
                        @endforelse
                    </div>

                    <form class="media-rail-caption-action" method="POST" action="{{ route('media.captions.fetch', $item) }}">
                        @csrf
                        <button class="button button-muted" type="submit">Find captions</button>
                        <span>{{ $item->subtitles->count() }} downloaded</span>
                    </form>
                </aside>
            </div>

            <div class="live-control-bar vod-control-bar" data-player-controls>
                <div class="vod-progress-row">
                    <time data-player-elapsed>0:00</time>
                    <label>
                        <span class="sr-only">Playback position</span>
                        <input data-player-seek type="range" min="0" max="1000" step="1" value="0">
                    </label>
                    <time data-player-remaining>−0:00</time>
                </div>

                <div class="live-control-group">
                    <button class="player-control player-control-primary" type="button" data-player-play aria-label="Play">
                        <svg class="icon-pause" aria-hidden="true" viewBox="0 0 24 24">
                            <path d="M8 5v14M16 5v14"/>
                        </svg>
                        <svg class="icon-play" aria-hidden="true" viewBox="0 0 24 24">
                            <path d="m8 5 11 7-11 7z"/>
                        </svg>
                    </button>
                    <button class="player-control" type="button" data-player-skip="-10" aria-label="Rewind 10 seconds">
                        <svg aria-hidden="true" viewBox="0 0 24 24">
                            <path d="M5 8V3m0 0h5M5 3l3.5 3.5A7 7 0 1 1 5 12"/>
                            <text x="9" y="15" class="player-icon-number">10</text>
                        </svg>
                    </button>
                    <button class="player-control" type="button" data-player-skip="10" aria-label="Forward 10 seconds">
                        <svg aria-hidden="true" viewBox="0 0 24 24">
                            <path d="M19 8V3m0 0h-5m5-0-3.5 3.5A7 7 0 1 0 19 12"/>
                            <text x="7" y="15" class="player-icon-number">10</text>
                        </svg>
                    </button>
                    <button class="player-control" type="button" data-player-mute aria-label="Mute">
                        <svg class="icon-volume" aria-hidden="true" viewBox="0 0 24 24">
                            <path d="M5 10v4h4l5 4V6l-5 4zM17 9a4 4 0 0 1 0 6M19 6a8 8 0 0 1 0 12"/>
                        </svg>
                        <svg class="icon-muted" aria-hidden="true" viewBox="0 0 24 24">
                            <path d="M5 10v4h4l5 4V6l-5 4zM18 10l4 4M22 10l-4 4"/>
                        </svg>
                    </button>
                    <label class="volume-control">
                        <span class="sr-only">Volume</span>
                        <input data-player-volume type="range" min="0" max="1" step="0.05" value="1">
                    </label>
                </div>

                <dl class="stream-diagnostics vod-diagnostics" aria-label="Playback information">
                    <div>
                        <dt>Playback</dt>
                        <dd data-stream-health data-health="connecting">Loading</dd>
                    </div>
                    <div>
                        <dt>Resolution</dt>
                        <dd data-stream-resolution>—</dd>
                    </div>
                    <div>
                        <dt>Bitrate</dt>
                        <dd data-stream-bitrate>
                            @if($averageBitrate)
                                {{ $averageBitrate >= 1000000 ? number_format($averageBitrate / 1000000, 1).' Mbps' : round($averageBitrate / 1000).' Kbps' }}
                            @else
                                —
                            @endif
                        </dd>
                    </div>
                </dl>

                <div class="vod-control-actions">
                    <button
                        class="player-control player-caption-control"
                        type="button"
                        data-player-captions
                        aria-label="Turn captions on"
                        @disabled($subtitleCount === 0)
                    >
                        <svg aria-hidden="true" viewBox="0 0 24 24">
                            <rect x="3" y="5" width="18" height="14" rx="1"/>
                            <path d="M10 10a2.5 2.5 0 1 0 0 4M18 10a2.5 2.5 0 1 0 0 4"/>
                        </svg>
                        <span data-player-caption-label>CC</span>
                    </button>
                    <button class="player-control" type="button" data-player-fullscreen aria-label="Enter full screen">
                        <svg aria-hidden="true" viewBox="0 0 24 24">
                            <path d="M8 3H3v5M16 3h5v5M8 21H3v-5M16 21h5v-5"/>
                        </svg>
                    </button>
                </div>
            </div>
            <p class="sr-only" role="status" aria-live="polite" data-player-navigation-status></p>
        </section>
    @else
        <section class="media-detail" aria-labelledby="player-heading">
            <div class="media-detail-content">
                <header class="page-header media-detail-header">
                    <div>
                        <p class="eyebrow">Direct play</p>
                        <h1 id="player-heading">{{ $item->title }}</h1>
                    </div>
                    <a class="button button-muted" href="{{ $libraryUrl }}">Back to library</a>
                </header>
                <div class="media-player-panel">
                    @include('media.partials.player', [
                        'item' => $item,
                        'progress' => $progress,
                        'sourceType' => 'direct',
                        'sourceUrl' => route('media.direct', $item),
                    ])
                </div>
            </div>
        </section>
    @endif
@endsection
