@extends('layouts.app')

@section('title', 'Live TV · Odissey')

@section('content')
    <section class="page-section">
        <header class="page-header">
            <div>
                <p class="eyebrow">Live television</p>
                <h1>Channels</h1>
                <p>Browse groups, search the imported catalog, and keep a personal favorites list.</p>
            </div>
        </header>

        <form class="filter-bar" method="GET" action="{{ route('iptv.channels.index') }}">
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
                <button class="button button-primary" type="submit">Filter</button>
            </div>
        </form>

        <div class="channel-grid">
            @forelse ($channels as $channel)
                @php
                    $programs = $guideByChannel->get($channel->id, collect());
                    $current = $programs->first(fn ($program) => $program->starts_at <= now() && $program->ends_at > now());
                    $next = $programs->first(fn ($program) => $program->starts_at > now());
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
                            <span>Now · ends {{ $current->ends_at->format('H:i') }}</span>
                        @else
                            <strong>Guide unavailable</strong>
                            <span>Refresh the short guide from provider settings.</span>
                        @endif
                        @if ($next)
                            <span>Next: {{ $next->title }} · {{ $next->starts_at->format('H:i') }}</span>
                        @endif
                    </div>

                    <form class="channel-actions" method="POST" action="{{ route('iptv.playback.store', $channel) }}">
                        @csrf
                        <button class="button button-primary" type="submit">Watch live</button>
                    </form>
                </article>
            @empty
                <div class="empty-state"><h2>No channels found</h2><p>Try another group or clear the search.</p></div>
            @endforelse
        </div>

        <div class="pagination">{{ $channels->links() }}</div>
    </section>
@endsection
