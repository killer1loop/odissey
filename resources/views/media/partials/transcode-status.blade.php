@php
    $status = $session?->status;
@endphp

@if ($session === null)
    <form
        hx-post="{{ route('media.transcodes.store', $item) }}"
        hx-target="this"
        hx-swap="outerHTML"
    >
        @csrf
        <p>This fixture uses codecs browsers do not play directly.</p>
        <label>Quality <select name="profile"><option value="auto">Automatic</option><option value="1080p">1080p</option><option value="720p">720p</option></select></label>
        @if(count($item->metadata['technical']['audio_tracks'] ?? []) > 1)<label>Audio <select name="audio_track">@foreach($item->metadata['technical']['audio_tracks'] as $track)<option value="{{ $track['index'] }}">{{ $track['title'] ?? $track['language'] ?? 'Track '.($track['index']+1) }}</option>@endforeach</select></label>@endif
        <button class="button button-primary" type="submit">Prepare HLS stream</button>
    </form>
@elseif (in_array($status, [\App\Models\TranscodeSession::STATUS_PENDING, \App\Models\TranscodeSession::STATUS_PROCESSING], true))
    <div
        id="transcode-status"
        hx-get="{{ route('media.transcodes.status', [$item, $session]) }}"
        hx-trigger="every 1s"
        hx-swap="outerHTML"
        data-background-request
        aria-live="polite"
    >
        <p class="eyebrow">FFmpeg job: {{ $status }}</p>
        <p>Preparing a temporary H.264/AAC HLS stream…</p>
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
        hx-post="{{ route('media.transcodes.store', $item) }}"
        hx-target="this"
        hx-swap="outerHTML"
    >
        @csrf
        <p>The stream could not be prepared. The underlying FFmpeg error was not exposed.</p>
        <button class="button button-primary" type="submit">Try again</button>
    </form>
@endif
