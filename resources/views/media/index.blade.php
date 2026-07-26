@extends('layouts.app')

@php
    $heading = $kind === 'music' ? 'Music' : ($library === 'tv' ? 'TV Shows' : 'Movies');
@endphp

@section('title', $heading.' · Odissey')

@section('content')
    <section class="page-section" aria-labelledby="media-heading">
        <header class="page-header">
            <div>
                <p class="eyebrow">Your libraries</p>
                <h1 id="media-heading">{{ $series ?? $heading }}</h1>
                @if ($series)
                    <p><a href="{{ route('media.index', array_filter(['kind' => 'video', 'library' => 'tv', 'source' => request('source')])) }}">TV Shows</a> / {{ $series }}</p>
                @endif
            </div>

            <form class="filter-bar library-filter" method="GET" role="search">
                <input type="hidden" name="kind" value="{{ $kind }}">
                @if ($library)<input type="hidden" name="library" value="{{ $library }}">@endif
                @if ($series)<input type="hidden" name="series" value="{{ $series }}">@endif
                <label class="control-field filter-search">
                    <span class="sr-only">Search this library</span>
                    <input name="q" type="search" value="{{ request('q') }}" placeholder="Search {{ strtolower($heading) }}">
                </label>
                <label class="control-field">
                    <span class="sr-only">Media source</span>
                    <select name="source" aria-label="Media source">
                        <option value="">All sources</option>
                        @foreach ($sources as $source)
                            <option value="{{ $source->id }}" @selected(request('source') === $source->id)>
                                {{ $source->name }} · {{ strtoupper($source->type) }}
                            </option>
                        @endforeach
                    </select>
                </label>
                <label class="checkbox-row">
                    <input type="checkbox" name="favorites" value="1" @checked(request()->boolean('favorites'))>
                    <span>Favorites</span>
                </label>
                <button class="button button-primary" type="submit">Filter</button>
            </form>
        </header>

        <nav class="filter-tabs" aria-label="Library type">
            <a class="{{ $kind === 'video' && $library === 'movies' ? 'is-active' : '' }}" href="{{ route('media.index', ['kind' => 'video', 'library' => 'movies']) }}">Movies</a>
            <a class="{{ $kind === 'video' && $library === 'tv' ? 'is-active' : '' }}" href="{{ route('media.index', ['kind' => 'video', 'library' => 'tv']) }}">TV Shows</a>
            <a class="{{ $kind === 'music' ? 'is-active' : '' }}" href="{{ route('media.index', ['kind' => 'music']) }}">Music</a>
        </nav>

        @if ($items->isEmpty() && $seriesGroups->isEmpty())
            <div class="empty-state">
                <h2>No matching {{ strtolower($heading) }}</h2>
                <p>@if(auth()->user()->isAdmin()) Add and scan local, S3, or WebDAV media, or synchronize an IPTV provider. @else Ask an administrator to add or synchronize a media source. @endif</p>
            </div>
        @else
            @if ($seriesGroups->isNotEmpty())
                <section class="library-section" aria-labelledby="series-heading">
                    <div class="shelf-heading"><h2 id="series-heading">TV series</h2></div>
                    <div class="library-grid">
                        @foreach ($seriesGroups as $name => $entries)
                            @php
                                $show = $entries->first(fn ($entry) => ($entry->metadata['kind'] ?? '') === 'series') ?? $entries->first();
                                $episodes = $entries->filter(fn ($entry) => ($entry->metadata['kind'] ?? '') === 'episode');
                            @endphp
                            <a class="media-card" href="{{ route('media.index', array_filter([
                                'kind' => 'video',
                                'library' => 'tv',
                                'series' => $name,
                                'source' => request('source'),
                            ])) }}">
                                <div class="media-poster">
                                    @if (($show->metadata['poster_cached'] ?? false))
                                        <img src="{{ route('media.artwork', [$show, 'poster']) }}" alt="">
                                    @else
                                        <span class="media-placeholder">{{ mb_strtoupper(mb_substr($name, 0, 2)) }}</span>
                                    @endif
                                    <span class="source-badge">
                                        {{ $show->source?->type === 'iptv' ? 'IPTV' : ($episodes->count().' eps') }}
                                    </span>
                                </div>
                                <div class="card-copy">
                                    <h3>{{ $name }}</h3>
                                    <p>
                                        {{ $show->source?->name ?? 'Personal library' }}
                                        @if ($episodes->isNotEmpty()) · {{ $episodes->count() }} episodes @endif
                                    </p>
                                </div>
                            </a>
                        @endforeach
                    </div>
                </section>
            @endif

            @if ($items->isNotEmpty())
                <section class="library-section" aria-labelledby="items-heading">
                    <div class="shelf-heading">
                        <h2 id="items-heading">{{ $kind === 'music' ? 'Albums & tracks' : ($library === 'tv' ? 'Episodes' : 'Movies') }}</h2>
                    </div>
                    <div class="library-grid {{ $kind === 'music' ? 'music-grid' : '' }}">
                        @foreach ($items as $item)
                            <article class="media-card">
                                <a href="{{ route('media.show', $item) }}">
                                    <div class="media-poster">
                                        @if (($item->metadata['poster_cached'] ?? false))
                                            <img src="{{ route('media.artwork', [$item, 'poster']) }}" alt="">
                                        @else
                                            <span class="media-placeholder">
                                                <svg aria-hidden="true" viewBox="0 0 64 64">
                                                    <rect x="8" y="14" width="48" height="36" rx="2"/>
                                                    <path d="m26 24 15 8-15 8z"/>
                                                </svg>
                                            </span>
                                        @endif
                                        <span class="source-badge">
                                            {{ $item->source ? strtoupper($item->source->type) : ($item->requires_transcode ? 'Convert' : 'Direct') }}
                                        </span>
                                        @if ($item->progress)
                                            <span class="poster-progress" style="--progress: {{ min(100, ($item->progress->position_ms / max(1, $item->progress->duration_ms ?? 1)) * 100) }}%"></span>
                                        @endif
                                    </div>
                                    <div class="card-copy">
                                        <h3>{{ $item->title }}</h3>
                                        <p>
                                            @if (($item->metadata['kind'] ?? '') === 'episode')
                                                S{{ str_pad((string) ($item->metadata['season_number'] ?? 0), 2, '0', STR_PAD_LEFT) }}E{{ str_pad((string) ($item->metadata['episode_number'] ?? 0), 2, '0', STR_PAD_LEFT) }}
                                            @else
                                                {{ $item->metadata['artist'] ?? $item->metadata['year'] ?? strtoupper($item->container ?? $kind) }}
                                            @endif
                                            @if ($item->progress) · Resume {{ floor($item->progress->position_ms / 60000) }}m @endif
                                        </p>
                                    </div>
                                </a>
                                <form class="card-favorite" method="POST" action="{{ $item->favorites->isEmpty() ? route('media.favorites.store', $item) : route('media.favorites.destroy', $item) }}">
                                    @csrf
                                    @if ($item->favorites->isNotEmpty()) @method('DELETE') @endif
                                    <button class="favorite-button" type="submit" aria-label="{{ $item->favorites->isEmpty() ? 'Add '.$item->title.' to favorites' : 'Remove '.$item->title.' from favorites' }}">
                                        {{ $item->favorites->isEmpty() ? '☆' : '★' }}
                                    </button>
                                </form>
                            </article>
                        @endforeach
                    </div>
                </section>

                <div class="pagination">{{ $items->links() }}</div>
            @endif
        @endif
    </section>
@endsection
