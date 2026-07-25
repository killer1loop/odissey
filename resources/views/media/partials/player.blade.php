<div
    data-media-player
    data-source-type="{{ $sourceType }}"
    data-source-url="{{ $sourceUrl }}"
    data-progress-url="{{ route('media.progress', $item) }}"
    data-progress-sequence="{{ $progress?->sequence ?? 0 }}"
    data-resume-seconds="{{ floor(($progress?->position_ms ?? 0) / 1000) }}"
>
    @if($item->media_kind === 'music')
    <audio
        controls
        preload="metadata"
        style="display:block;width:100%;"
    >
        Your browser does not support HTML audio.
    </audio>
    @else
    <video
        controls
        playsinline
        preload="metadata"
        style="display: block; width: 100%; max-height: min(72vh, 760px); border-radius: 0.8rem; background: #000;"
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
    <p data-player-message role="status" aria-live="polite"></p>
</div>
