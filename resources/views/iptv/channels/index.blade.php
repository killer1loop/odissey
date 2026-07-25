@extends('layouts.app')

@section('title', 'Live TV · Odissey')

@section('content')
    @include('iptv.styles')

    <section class="iptv-page">
        <header class="iptv-header">
            <div>
                <p class="eyebrow">Live television</p>
                <h1>Channels</h1>
                <p>Browse groups, search the imported catalog, and keep a personal favorites list.</p>
            </div>
        </header>

        <form class="iptv-filter" method="GET" action="{{ route('iptv.channels.index') }}">
            <label class="iptv-label">
                <span class="sr-only">Search channels</span>
                <input class="iptv-input" type="search" name="q" maxlength="100" value="{{ $search }}" placeholder="Search channels">
            </label>
            <label class="iptv-label">
                <span class="sr-only">Channel group</span>
                <select class="iptv-input" name="group">
                    <option value="">All groups</option>
                    @foreach ($groups as $group)
                        <option value="{{ $group->id }}" @selected($selectedGroup === $group->id)>
                            {{ $group->name }} ({{ $group->channels_count }})
                        </option>
                    @endforeach
                </select>
            </label>
            <div class="iptv-card-actions" style="margin-top: 0">
                <label class="iptv-check">
                    <input type="checkbox" name="favorites" value="1" @checked($favoritesOnly)>
                    <span>Favorites</span>
                </label>
                <button class="button button-primary" type="submit">Filter</button>
            </div>
        </form>

        <div class="iptv-grid">
            @forelse ($channels as $channel)
                @php
                    $programs = $guideByChannel->get($channel->id, collect());
                    $current = $programs->first(fn ($program) => $program->starts_at <= now() && $program->ends_at > now());
                    $next = $programs->first(fn ($program) => $program->starts_at > now());
                @endphp
                <article class="iptv-card">
                    <div class="iptv-channel-head">
                        <div class="iptv-channel-mark" aria-hidden="true">{{ mb_strtoupper(mb_substr($channel->name, 0, 2)) }}</div>
                        <div style="min-width: 0; flex: 1">
                            <h2>{{ $channel->name }}</h2>
                            <p>{{ $channel->group?->name ?? 'Other channels' }}</p>
                        </div>
                        @include('iptv.channels.favorite-button', [
                            'channel' => $channel,
                            'isFavorite' => $favoriteIds->contains($channel->id),
                        ])
                    </div>

                    <div class="iptv-guide">
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

                    <form class="iptv-card-actions" method="POST" action="{{ route('iptv.playback.store', $channel) }}">
                        @csrf
                        <button class="button button-primary" type="submit">Watch live</button>
                    </form>
                </article>
            @empty
                <p class="iptv-empty">No channels match these filters.</p>
            @endforelse
        </div>

        <div style="margin-top: 1.5rem">{{ $channels->links() }}</div>
    </section>
@endsection
