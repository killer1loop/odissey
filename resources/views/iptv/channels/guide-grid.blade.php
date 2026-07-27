@php
    $windowSeconds = max(1, $guideEnd->timestamp - $guideStart->timestamp);
    $nowSeconds = $guideNow->timestamp - $guideStart->timestamp;
    $nowPosition = max(0, min(100, ($nowSeconds / $windowSeconds) * 100));
    $nowIsVisible = $guideNow >= $guideStart && $guideNow < $guideEnd;
@endphp

<div class="epg-heading">
    <div>
        <strong>{{ $guideStart->timezone($viewerTimezone)->format('D j M') }}</strong>
        <span>
            {{ $guideStart->timezone($viewerTimezone)->format('H:i') }}
            – {{ $guideEnd->timezone($viewerTimezone)->format('H:i') }}
        </span>
    </div>
    <p><span class="epg-live-key" aria-hidden="true"></span> Live now</p>
</div>

@if ($channels->isEmpty())
    <div class="empty-state">
        <h2>{{ $favoritesOnly ? 'No favorite channels yet' : 'No channels found' }}</h2>
        <p>{{ $favoritesOnly ? 'Add channels with the star control to build your personal guide.' : 'Try another group or clear the search.' }}</p>
    </div>
@else
    <div
        class="epg-shell"
        role="region"
        aria-label="Six-hour television guide"
        tabindex="0"
        data-live-tv-view="guide"
    >
        <div class="epg-grid" role="grid" aria-rowcount="{{ $channels->count() + 1 }}">
            <div class="epg-time-row" role="row">
                <div class="epg-corner" role="columnheader">
                    <strong>Channels</strong>
                    <span>{{ $channels->total() }} available</span>
                </div>
                <div class="epg-time-track" role="presentation">
                    @for ($hour = 0; $hour < 6; $hour++)
                        @php
                            $tick = $guideStart->addHours($hour)->timezone($viewerTimezone);
                        @endphp
                        <div class="epg-time" role="columnheader">
                            <strong>{{ $tick->format('H:i') }}</strong>
                            @if ($hour === 0 || $tick->format('H:i') === '00:00')
                                <span>{{ $tick->format('D j') }}</span>
                            @endif
                        </div>
                    @endfor
                    @if ($nowIsVisible)
                        <div class="epg-now-marker epg-now-marker-header" style="left: {{ $nowPosition }}%" aria-hidden="true">
                            <span>Now</span>
                        </div>
                    @endif
                </div>
            </div>

            @foreach ($channels as $channel)
                @php
                    $programs = $guideByChannel
                        ->get($channel->id, collect())
                        ->groupBy(
                            fn ($program) => max(
                                $guideStart->timestamp,
                                $program->starts_at->timestamp,
                            )
                        )
                        ->map(
                            fn ($slot) => $slot
                                ->sort(function ($first, $second) {
                                    $startComparison = $second->starts_at->timestamp
                                        <=> $first->starts_at->timestamp;

                                    return $startComparison !== 0
                                        ? $startComparison
                                        : $second->ends_at->timestamp
                                            <=> $first->ends_at->timestamp;
                                })
                                ->first()
                        )
                        ->sortBy(fn ($program) => $program->starts_at->timestamp)
                        ->values();
                @endphp
                <div class="epg-row" role="row">
                    <div class="epg-channel" role="rowheader">
                        <form class="epg-channel-play" method="POST" action="{{ route('iptv.playback.store', $channel) }}">
                            @csrf
                            <button type="submit" aria-label="Watch {{ $channel->name }} live">
                                @include('iptv.channels.icon', [
                                    'channel' => $channel,
                                    'class' => 'epg-channel-mark',
                                ])
                                <span class="epg-channel-copy">
                                    <strong>
                                        @if ($channel->channel_number)
                                            <small>{{ $channel->channel_number }}</small>
                                        @endif
                                        {{ $channel->name }}
                                    </strong>
                                    <span>{{ $channel->group?->name ?? 'Other channels' }}</span>
                                </span>
                                <svg aria-hidden="true" viewBox="0 0 24 24">
                                    <path d="m9 7 8 5-8 5z"/>
                                </svg>
                            </button>
                        </form>
                        @include('iptv.channels.favorite-button', [
                            'channel' => $channel,
                            'isFavorite' => $favoriteIds->contains($channel->id),
                        ])
                    </div>

                    <div class="epg-program-track" role="gridcell">
                        @if ($nowIsVisible)
                            <div class="epg-now-line" style="left: {{ $nowPosition }}%" aria-hidden="true"></div>
                        @endif

                        @forelse ($programs as $programIndex => $program)
                            @php
                                $visibleStart = max($guideStart->timestamp, $program->starts_at->timestamp);
                                $visibleEnd = min($guideEnd->timestamp, $program->ends_at->timestamp);
                                $nextProgram = $programs->get($programIndex + 1);

                                if (
                                    $nextProgram
                                    && $nextProgram->starts_at->timestamp > $visibleStart
                                ) {
                                    $visibleEnd = min(
                                        $visibleEnd,
                                        $nextProgram->starts_at->timestamp,
                                    );
                                }

                                $offsetSeconds = max(0, $visibleStart - $guideStart->timestamp);
                                $durationSeconds = max(1, $visibleEnd - $visibleStart);
                                $programStart = ($offsetSeconds / $windowSeconds) * 100;
                                $programWidth = ($durationSeconds / $windowSeconds) * 100;
                                $isLive = $program->starts_at <= $guideNow && $program->ends_at > $guideNow;
                                $viewerStart = $program->starts_at->timezone($viewerTimezone)->format('H:i');
                                $viewerEnd = $program->ends_at->timezone($viewerTimezone)->format('H:i');
                            @endphp
                            @continue($visibleEnd <= $visibleStart)
                            <form
                                class="epg-program-block {{ $isLive ? 'is-live' : '' }}"
                                method="POST"
                                action="{{ route('iptv.playback.store', $channel) }}"
                                style="left: {{ $programStart }}%; width: {{ $programWidth }}%"
                            >
                                @csrf
                                <button
                                    type="submit"
                                    data-epg-program
                                    data-epg-title="{{ $program->title }}"
                                    data-epg-channel="{{ $channel->name }}"
                                    data-epg-time="{{ $viewerStart }} – {{ $viewerEnd }}"
                                    data-epg-description="{{ $program->description }}"
                                    aria-label="Watch {{ $channel->name }} live: {{ $program->title }}"
                                >
                                    <strong>{{ $program->title }}</strong>
                                    <span>
                                        {{ $viewerStart }} – {{ $viewerEnd }}
                                    </span>
                                    @if ($isLive)
                                        <small>Live</small>
                                    @endif
                                </button>
                            </form>
                        @empty
                            <form class="epg-no-program" method="POST" action="{{ route('iptv.playback.store', $channel) }}">
                                @csrf
                                <button type="submit">
                                    <strong>No schedule information</strong>
                                    <span>Watch {{ $channel->name }} live</span>
                                </button>
                            </form>
                        @endforelse
                    </div>
                </div>
            @endforeach
        </div>
    </div>
@endif
