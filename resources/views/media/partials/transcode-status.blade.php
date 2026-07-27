@php
    $status = $session?->status;
    $errorMessages = [
        'cache_quota_exceeded' => 'There is not enough temporary space to prepare this stream.',
        'output_incomplete' => 'The converter stopped before it produced a playable stream.',
        'remote_source_capacity_exhausted' => 'The temporary media capacity is currently in use.',
        'remote_source_too_large' => 'This source is larger than the configured conversion limit.',
        'source_unavailable' => 'The source could not be opened. Check that the provider or media location is available.',
        'transcode_capacity_timeout' => 'The converter stayed busy for too long.',
        'transcode_failed' => 'FFmpeg could not convert this media.',
        'transcode_interrupted' => 'The previous conversion was interrupted by an application restart.',
        'transcode_timeout' => 'The conversion exceeded the configured runtime limit.',
    ];
@endphp

@if ($session === null)
    <form
        class="transcode-panel transcode-form"
        data-transcode-status
        hx-post="{{ route('media.transcodes.store', $item) }}"
        hx-target="this"
        hx-swap="outerHTML"
        hx-disabled-elt="button"
    >
        @csrf
        <div class="transcode-panel-heading">
            <span class="transcode-panel-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24">
                    <path d="m8 5 11 7-11 7z"/>
                </svg>
            </span>
            <div>
                <p class="eyebrow">Browser-compatible playback</p>
                <h2>Convert this media to HLS</h2>
            </div>
        </div>
        <p class="transcode-panel-copy">
            This file uses a format the browser cannot play directly. Odissey will create a temporary H.264/AAC stream without changing the original.
        </p>
        <div class="transcode-options">
            <label>
                <span>Quality</span>
                <select name="profile">
                    <option value="auto">Automatic</option>
                    <option value="1080p">1080p</option>
                    <option value="720p">720p</option>
                </select>
            </label>
            @if(count($item->metadata['technical']['audio_tracks'] ?? []) > 1)
                <label>
                    <span>Audio track</span>
                    <select name="audio_track">
                        @foreach($item->metadata['technical']['audio_tracks'] as $track)
                            <option value="{{ $track['index'] }}">
                                {{ $track['title'] ?? $track['language'] ?? 'Track '.($track['index']+1) }}
                            </option>
                        @endforeach
                    </select>
                </label>
            @endif
        </div>
        <div class="transcode-form-actions">
            <button class="button button-primary" type="submit">Start conversion</button>
            <span>Playback opens after the first segments are ready.</span>
        </div>
    </form>
@elseif (in_array($status, [\App\Models\TranscodeSession::STATUS_PENDING, \App\Models\TranscodeSession::STATUS_PROCESSING], true))
    <div
        id="transcode-status"
        class="transcode-panel transcode-progress"
        data-transcode-status
        hx-get="{{ route('media.transcodes.status', [$item, $session]) }}"
        hx-trigger="every 2s"
        hx-swap="outerHTML"
        data-background-request
        aria-live="polite"
    >
        <div class="transcode-panel-heading">
            <span class="transcode-spinner" aria-hidden="true"></span>
            <div>
                <p class="eyebrow">
                    {{ $status === \App\Models\TranscodeSession::STATUS_PENDING ? 'Waiting for converter' : 'Creating HLS stream' }}
                </p>
                <h2>
                    {{ $status === \App\Models\TranscodeSession::STATUS_PENDING ? 'Queued for playback' : 'Preparing the first video segments' }}
                </h2>
            </div>
        </div>
        <p class="transcode-panel-copy">
            @if($status === \App\Models\TranscodeSession::STATUS_PENDING)
                Your request is queued. It will start as soon as a conversion slot is available.
            @else
                FFmpeg is reading directly from the media source. The player will replace this panel as soon as playback can begin.
            @endif
        </p>
        <div class="transcode-steps" aria-label="Conversion progress">
            <span data-complete="true">Requested</span>
            <span data-complete="{{ $status === \App\Models\TranscodeSession::STATUS_PROCESSING ? 'true' : 'false' }}">Converting</span>
            <span data-complete="false">Ready</span>
        </div>
        @if($session->started_at)
            <p class="transcode-elapsed">Running for {{ $session->started_at->diffForHumans(now(), true) }}</p>
        @endif
    </div>
@elseif ($session->isAvailable())
    @include('media.partials.player', [
        'item' => $item,
        'progress' => $progress ?? null,
        'sourceType' => 'hls',
        'sourceUrl' => route('media.transcodes.manifest', [$item, $session]),
    ])
@else
    <form
        class="transcode-panel transcode-error"
        data-transcode-status
        hx-post="{{ route('media.transcodes.store', $item) }}"
        hx-target="this"
        hx-swap="outerHTML"
        hx-disabled-elt="button"
    >
        @csrf
        <div class="transcode-panel-heading">
            <span class="transcode-panel-icon transcode-panel-icon-error" aria-hidden="true">!</span>
            <div>
                <p class="eyebrow">Conversion stopped</p>
                <h2>Playback is not ready</h2>
            </div>
        </div>
        <p class="transcode-panel-copy">
            {{ $errorMessages[$session->error_code] ?? 'The stream could not be prepared. Technical details were hidden for security.' }}
        </p>
        <div class="transcode-form-actions">
            <button class="button button-primary" type="submit">Retry conversion</button>
        </div>
    </form>
@endif
