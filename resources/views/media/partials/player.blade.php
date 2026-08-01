@if($item->media_kind === 'music')
<div
    data-media-player
    data-source-type="{{ $sourceType }}"
    data-source-url="{{ $sourceUrl }}"
    data-progress-url="{{ route('media.progress', $item) }}"
    data-progress-sequence="{{ $progress?->sequence ?? 0 }}"
    data-resume-seconds="{{ floor(($progress?->position_ms ?? 0) / 1000) }}"
    class="media-player music-player"
    data-player-state="connecting"
    data-playing="false"
    data-muted="false"
    tabindex="0"
    aria-label="Audio player for {{ $item->title }}"
>
    <div class="music-player-visual" aria-hidden="true">
        @if ($itemArtworkAvailable)
            <img src="{{ route('media.artwork', [$itemArtwork, 'poster']) }}" alt="">
        @else
            <span>{{ mb_strtoupper(mb_substr($item->title, 0, 1)) }}</span>
        @endif
        <div class="music-waveform">
            @foreach ([34, 58, 78, 46, 86, 64, 94, 52, 72, 42, 82, 61, 90, 54, 36] as $height)
                <i style="--wave-height: {{ $height }}%"></i>
            @endforeach
        </div>
    </div>

    <div class="music-player-content">
        <div class="music-now-playing">
            <p class="eyebrow">Now playing</p>
            <h2>{{ $item->title }}</h2>
            <p>
                {{ $item->metadata['artist'] ?? 'Unknown artist' }}
                @if($item->metadata['album'] ?? null) · {{ $item->metadata['album'] }} @endif
                @if($item->metadata['year'] ?? null) · {{ $item->metadata['year'] }} @endif
            </p>
        </div>

    <audio
        preload="metadata"
        class="music-audio-engine"
        data-media-video
        data-source-type="{{ $sourceType }}"
        data-source-url="{{ $sourceUrl }}"
    >
        Your browser does not support HTML audio.
    </audio>

        <div class="music-progress-row">
            <time data-player-elapsed>0:00</time>
            <label>
                <span class="sr-only">Playback position</span>
                <input data-player-seek type="range" min="0" max="1000" step="1" value="0">
            </label>
            <time data-player-remaining>−0:00</time>
        </div>

        <div class="music-control-row">
            <div class="music-controls">
                <button class="player-control" type="button" data-player-skip="-10" aria-label="Rewind 10 seconds">
                    <svg aria-hidden="true" viewBox="0 0 24 24">
                        <path d="M5 8V3m0 0h5M5 3l3.5 3.5A7 7 0 1 1 5 12"/>
                        <text x="9" y="15" class="player-icon-number">10</text>
                    </svg>
                </button>
                <button class="player-control player-control-primary music-play-button" type="button" data-player-play aria-label="Play">
                    <svg class="icon-pause" aria-hidden="true" viewBox="0 0 24 24">
                        <path d="M8 5v14M16 5v14"/>
                    </svg>
                    <svg class="icon-play" aria-hidden="true" viewBox="0 0 24 24">
                        <path d="m8 5 11 7-11 7z"/>
                    </svg>
                </button>
                <button class="player-control" type="button" data-player-skip="10" aria-label="Forward 10 seconds">
                    <svg aria-hidden="true" viewBox="0 0 24 24">
                        <path d="M19 8V3m0 0h-5m5 0-3.5 3.5A7 7 0 1 0 19 12"/>
                        <text x="7" y="15" class="player-icon-number">10</text>
                    </svg>
                </button>
            </div>

            <div class="music-volume">
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

            <div class="music-playback-state">
                <span data-stream-health data-health="connecting">Loading</span>
                <small>{{ strtoupper($item->container ?: pathinfo($item->relative_path ?? '', PATHINFO_EXTENSION) ?: 'Audio') }}</small>
            </div>
        </div>

        <p class="player-message music-player-message" data-player-message role="status" aria-live="polite">Preparing playback…</p>
    </div>
</div>
@else
<video
    playsinline
    preload="metadata"
    class="media-video"
    data-media-video
    data-source-type="{{ $sourceType }}"
    data-source-url="{{ $sourceUrl }}"
    aria-label="{{ $item->title }}"
>
    @foreach($item->metadata['technical']['subtitle_tracks'] ?? [] as $track)
        <track kind="subtitles" src="{{ route('media.subtitles',[$item,$track['index']]) }}" srclang="{{ $track['language'] ?? 'und' }}" label="{{ $track['title'] ?? strtoupper($track['language'] ?? 'Subtitle '.($track['index']+1)) }}">
    @endforeach
    @foreach($item->subtitles as $caption)
        <track kind="subtitles" src="{{ route('media.captions.show',[$item,$caption]) }}" srclang="{{ $caption->language }}" label="{{ $caption->label }} · {{ ucfirst($caption->provider) }}{{ $caption->hearing_impaired ? ' · SDH' : '' }}">
    @endforeach
    Your browser does not support HTML video.
</video>
@endif
