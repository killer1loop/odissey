@php
    $iconClass = $class ?? '';
    $loadingMode = $loading ?? 'lazy';
    $initials = mb_strtoupper(mb_substr($channel->name, 0, 2));
@endphp

<span class="channel-logo {{ $iconClass }}" data-channel-icon aria-hidden="true">
    <span class="channel-logo-fallback">{{ $initials }}</span>
    @if ($channel->stream_icon)
        <img
            src="{{ route('iptv.channels.icon', $channel) }}"
            alt=""
            loading="{{ $loadingMode }}"
            decoding="async"
        >
    @endif
</span>
