@extends('layouts.app')

@section('title', $session->channel->name.' · Live TV · Odissey')
@section('body-class', 'player-page')

@section('topbar')
    <div class="player-topbar-copy">
        <span class="live-indicator" aria-hidden="true"></span>
        <div>
            <p>{{ $session->channel->group?->name ?? 'Live TV' }}</p>
            <h1>{{ $session->channel->name }}</h1>
        </div>
        @if ($programs->first())
            <span class="player-program">Now · {{ $programs->first()->title }}</span>
        @endif
    </div>
    <form method="POST" action="{{ route('iptv.playback.destroy', $session) }}">
        @csrf
        @method('DELETE')
        <button class="icon-button square-button" type="submit" aria-label="Stop playback and return to channels">
            <svg aria-hidden="true" viewBox="0 0 24 24">
                <path d="m6 6 12 12M18 6 6 18"/>
            </svg>
        </button>
    </form>
@endsection

@section('content')
    <section
        class="live-player-shell"
        data-iptv-player
        data-manifest-url="{{ route('iptv.playback.manifest', $session) }}"
        aria-label="{{ $session->channel->name }} live stream player"
    >
        <div class="live-video-stage">
            <video playsinline preload="metadata" aria-label="{{ $session->channel->name }} live stream"></video>
            <div class="live-video-message" data-iptv-player-status role="status" aria-live="polite">
                Connecting to live stream…
            </div>
        </div>

        <div class="live-control-bar" data-player-controls>
            <div class="live-control-group">
                <button class="player-control player-control-primary" type="button" data-player-play aria-label="Pause live stream">
                    <svg class="icon-pause" aria-hidden="true" viewBox="0 0 24 24">
                        <path d="M8 5v14M16 5v14"/>
                    </svg>
                    <svg class="icon-play" aria-hidden="true" viewBox="0 0 24 24">
                        <path d="m8 5 11 7-11 7z"/>
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
                <span class="live-badge"><span></span>Live</span>
            </div>

            <dl class="stream-diagnostics" aria-label="Stream information">
                <div>
                    <dt>Health</dt>
                    <dd data-stream-health data-health="connecting">Connecting</dd>
                </div>
                <div>
                    <dt>Resolution</dt>
                    <dd data-stream-resolution>—</dd>
                </div>
                <div>
                    <dt>Bitrate</dt>
                    <dd data-stream-bitrate>—</dd>
                </div>
            </dl>

            <button class="player-control" type="button" data-player-fullscreen aria-label="Enter full screen">
                <svg aria-hidden="true" viewBox="0 0 24 24">
                    <path d="M8 3H3v5M16 3h5v5M8 21H3v-5M16 21h5v-5"/>
                </svg>
            </button>
        </div>
    </section>
@endsection
