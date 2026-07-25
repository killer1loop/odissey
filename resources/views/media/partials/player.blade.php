<div
    data-media-player
    data-source-type="{{ $sourceType }}"
    data-source-url="{{ $sourceUrl }}"
    data-progress-url="{{ route('media.progress', $item) }}"
    data-progress-sequence="{{ $progress?->sequence ?? 0 }}"
    data-resume-seconds="{{ floor(($progress?->position_ms ?? 0) / 1000) }}"
>
    <video
        controls
        playsinline
        preload="metadata"
        style="display: block; width: 100%; max-height: min(72vh, 760px); border-radius: 0.8rem; background: #000;"
    >
        Your browser does not support HTML video.
    </video>
    <p data-player-message role="status" aria-live="polite"></p>
</div>
