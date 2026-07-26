<div
    data-media-player
    data-source-type="{{ $sourceType }}"
    data-source-url="{{ $sourceUrl }}"
    data-progress-url="{{ route('media.progress', $item) }}"
    data-progress-sequence="{{ $progress?->sequence ?? 0 }}"
    data-resume-seconds="{{ floor(($progress?->position_ms ?? 0) / 1000) }}"
    class="media-player"
>
    @if($item->media_kind === 'music')
    <audio
        controls
        preload="metadata"
        class="media-audio"
    >
        Your browser does not support HTML audio.
    </audio>
    @else
    <video
        controls
        playsinline
        preload="metadata"
        class="media-video"
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
    <p class="player-message" data-player-message role="status" aria-live="polite"></p>
</div>
