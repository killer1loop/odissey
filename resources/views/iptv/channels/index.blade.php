@php
    $pageTitle = $favoritesOnly ? 'Favorite channels' : 'Live TV';
    $baseQuery = array_filter([
        'q' => $search !== '' ? $search : null,
        'group' => $selectedGroup,
        'favorites' => $favoritesOnly ? 1 : null,
    ], fn ($value) => $value !== null && $value !== '');
    $guideUrl = route('iptv.channels.index', [...$baseQuery, 'view' => 'guide']);
    $channelsUrl = route('iptv.channels.index', [...$baseQuery, 'view' => 'channels']);
@endphp

@extends('layouts.app')

@section('title', $pageTitle.' · Odissey')

@section('content')
    <section class="page-section live-tv-page">
        <header class="page-header live-tv-header">
            <div>
                <p class="eyebrow">{{ $favoritesOnly ? 'Your live television' : 'Live television' }}</p>
                <h1>{{ $pageTitle }}</h1>
                <p>
                    {{ $favoritesOnly
                        ? 'Your saved channels, with what is on now and what starts next.'
                        : 'Browse the schedule, filter channel groups, and start watching instantly.' }}
                </p>
            </div>

            <nav class="view-switcher" aria-label="Live TV view">
                <a
                    class="{{ $viewMode === 'guide' ? 'is-active' : '' }}"
                    href="{{ $guideUrl }}"
                    @if ($viewMode === 'guide') aria-current="page" @endif
                >
                    <svg aria-hidden="true" viewBox="0 0 24 24">
                        <path d="M3 6h18M8 6v15M3 11h18M3 16h18"/>
                    </svg>
                    <span>TV guide</span>
                </a>
                <a
                    class="{{ $viewMode === 'channels' ? 'is-active' : '' }}"
                    href="{{ $channelsUrl }}"
                    @if ($viewMode === 'channels') aria-current="page" @endif
                >
                    <svg aria-hidden="true" viewBox="0 0 24 24">
                        <rect x="3" y="4" width="7" height="7"/>
                        <rect x="14" y="4" width="7" height="7"/>
                        <rect x="3" y="15" width="7" height="5"/>
                        <rect x="14" y="15" width="7" height="5"/>
                    </svg>
                    <span>Channel grid</span>
                </a>
            </nav>
        </header>

        <form class="filter-bar live-tv-filters" method="GET" action="{{ route('iptv.channels.index') }}">
            <input type="hidden" name="view" value="{{ $viewMode }}">
            <label class="control-field filter-search">
                <span class="sr-only">Search channels</span>
                <input type="search" name="q" maxlength="100" value="{{ $search }}" placeholder="Search channels">
            </label>
            <label class="control-field">
                <span class="sr-only">Channel group</span>
                <select name="group">
                    <option value="">All groups</option>
                    @foreach ($groups as $group)
                        <option value="{{ $group->id }}" @selected($selectedGroup === $group->id)>
                            {{ $group->name }} ({{ $group->channels_count }})
                        </option>
                    @endforeach
                </select>
            </label>
            <div class="filter-actions">
                <label class="checkbox-row">
                    <input type="checkbox" name="favorites" value="1" @checked($favoritesOnly)>
                    <span>Favorites</span>
                </label>
                <button class="button button-primary" type="submit">Apply</button>
            </div>
        </form>

        @if ($viewMode === 'guide')
            @include('iptv.channels.guide-grid')
        @else
            <div class="channel-grid" data-live-tv-view="channels">
                @forelse ($channels as $channel)
                    @php
                        $programs = $guideByChannel->get($channel->id, collect());
                        $current = $programs->first(fn ($program) => $program->starts_at <= $guideNow && $program->ends_at > $guideNow);
                        $next = $programs->first(fn ($program) => $program->starts_at > $guideNow);
                    @endphp
                    <article class="channel-card">
                        <div class="channel-card-head">
                            <div class="channel-mark" aria-hidden="true">{{ mb_strtoupper(mb_substr($channel->name, 0, 2)) }}</div>
                            <div class="channel-title">
                                <h2>{{ $channel->name }}</h2>
                                <p>{{ $channel->group?->name ?? 'Other channels' }}</p>
                            </div>
                            @include('iptv.channels.favorite-button', [
                                'channel' => $channel,
                                'isFavorite' => $favoriteIds->contains($channel->id),
                            ])
                        </div>

                        <div class="channel-guide">
                            @if ($current)
                                <strong>{{ $current->title }}</strong>
                                <span>Now · ends {{ $current->ends_at->timezone($viewerTimezone)->format('H:i') }}</span>
                            @else
                                <strong>Guide unavailable</strong>
                                <span>The hourly guide refresh will check again automatically.</span>
                            @endif
                            @if ($next)
                                <span>Next: {{ $next->title }} · {{ $next->starts_at->timezone($viewerTimezone)->format('H:i') }}</span>
                            @endif
                        </div>

                        <form class="channel-actions" method="POST" action="{{ route('iptv.playback.store', $channel) }}">
                            @csrf
                            <button class="button button-primary" type="submit">Watch live</button>
                        </form>
                    </article>
                @empty
                    <div class="empty-state">
                        <h2>{{ $favoritesOnly ? 'No favorite channels yet' : 'No channels found' }}</h2>
                        <p>{{ $favoritesOnly ? 'Add channels with the star control to see them here.' : 'Try another group or clear the search.' }}</p>
                    </div>
                @endforelse
            </div>
        @endif

        <div class="pagination">{{ $channels->links() }}</div>
    </section>
@endsection
