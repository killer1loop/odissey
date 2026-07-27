@extends('layouts.app')

@section('title', $session->channel->name.' · Live TV · Odissey')
@section('body-class', 'player-page')

@section('topbar')
    <div class="player-topbar-copy">
        <span class="live-indicator" aria-hidden="true"></span>
        @include('iptv.channels.icon', [
            'channel' => $session->channel,
            'class' => 'player-channel-logo',
            'loading' => 'eager',
        ])
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
        data-restart-url="{{ route('iptv.playback.restart', $session) }}"
        data-diagnostic-url="{{ route('iptv.playback.diagnostics', $session) }}"
        data-active-channel-id="{{ $session->channel_id }}"
        data-rail-open="false"
        aria-label="{{ $session->channel->name }} live stream player"
        tabindex="0"
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
                <video playsinline preload="metadata" aria-label="{{ $session->channel->name }} live stream"></video>
                <div class="live-video-message" data-iptv-player-status role="status" aria-live="polite">
                    Connecting to live stream…
                </div>
                <button
                    class="player-rail-trigger"
                    type="button"
                    data-player-rail-toggle
                    aria-controls="favorite-channel-rail"
                    aria-expanded="false"
                    aria-label="Open channel list"
                    title="Channels"
                >
                    <svg aria-hidden="true" viewBox="0 0 24 24">
                        <path d="M4 6h16M4 12h16M4 18h16"/>
                    </svg>
                    <span>Channels</span>
                </button>
            </div>

            <aside
                id="favorite-channel-rail"
                class="player-channel-rail"
                data-player-channel-rail
                aria-label="Favorite channels and two-hour guide"
            >
                <header class="player-rail-header">
                    <div>
                        <p>Favorite channels</p>
                        <h2>Next two hours</h2>
                    </div>
                    <button
                        class="player-rail-close"
                        type="button"
                        data-player-rail-close
                        aria-label="Close favorite channels"
                    >
                        <svg aria-hidden="true" viewBox="0 0 24 24">
                            <path d="m6 6 12 12M18 6 6 18"/>
                        </svg>
                    </button>
                </header>

                <div class="player-rail-hint" aria-hidden="true">
                    <span><kbd>↑</kbd><kbd>↓</kbd> switch</span>
                    <span><kbd>F</kbd> full screen</span>
                    <span><kbd>Esc</kbd> exit</span>
                </div>

                <div class="player-channel-list" data-player-channel-list>
                    @forelse ($favoriteChannels as $favoriteChannel)
                        @php
                            $railPrograms = $favoriteGuide->get($favoriteChannel->id, collect());
                            $isCurrentChannel = $favoriteChannel->is($session->channel);
                        @endphp
                        <form
                            class="player-channel-item {{ $isCurrentChannel ? 'is-active' : '' }}"
                            method="POST"
                            action="{{ route('iptv.playback.store', $favoriteChannel) }}"
                            data-favorite-channel
                            data-channel-id="{{ $favoriteChannel->id }}"
                        >
                            @csrf
                            <button
                                type="submit"
                                aria-label="Switch to {{ $favoriteChannel->name }}"
                                @if ($isCurrentChannel) aria-current="true" @endif
                            >
                                @include('iptv.channels.icon', [
                                    'channel' => $favoriteChannel,
                                    'class' => 'player-rail-logo',
                                ])
                                <span class="player-rail-copy">
                                    <strong>{{ $favoriteChannel->name }}</strong>
                                    <small>{{ $favoriteChannel->group?->name ?? 'Live TV' }}</small>
                                    <span class="player-rail-programs">
                                        @forelse ($railPrograms as $railProgram)
                                            <span>
                                                <time datetime="{{ $railProgram->starts_at->toIso8601String() }}">
                                                    {{ $railProgram->starts_at <= $guideNow ? 'Now' : $railProgram->starts_at->timezone($viewerTimezone)->format('H:i') }}
                                                </time>
                                                {{ $railProgram->title }}
                                            </span>
                                        @empty
                                            <span><time>—</time>No guide information</span>
                                        @endforelse
                                    </span>
                                </span>
                                @if ($isCurrentChannel)
                                    <span class="player-channel-playing">Playing</span>
                                @endif
                            </button>
                        </form>
                    @empty
                        <div class="player-rail-empty">
                            <strong>No favorite channels</strong>
                            <p>Add favorites from Live TV to switch channels with the remote or keyboard.</p>
                            <a href="{{ route('iptv.channels.index', ['favorites' => 1]) }}">Open Live TV</a>
                        </div>
                    @endforelse
                </div>
            </aside>
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
                <div>
                    <dt>Recovery</dt>
                    <dd data-stream-recovery>Automatic</dd>
                </div>
            </dl>

            <button class="player-control" type="button" data-player-fullscreen aria-label="Enter full screen">
                <svg aria-hidden="true" viewBox="0 0 24 24">
                    <path d="M8 3H3v5M16 3h5v5M8 21H3v-5M16 21h5v-5"/>
                </svg>
            </button>
        </div>
        <p class="sr-only" role="status" aria-live="polite" data-player-navigation-status></p>
    </section>
@endsection
