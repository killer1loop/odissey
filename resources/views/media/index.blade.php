@extends('layouts.app')

@section('title', ($kind === 'music' ? 'Music' : 'Video').' · Odissey')

@section('content')
    <section class="page-section" aria-labelledby="media-heading">
        <header class="page-header">
            <div>
                <p class="eyebrow">Your libraries</p>
                <h1 id="media-heading">{{ $kind === 'music' ? 'Music' : 'Movies & TV' }}</h1>
            </div>

            <form class="filter-bar library-filter" method="GET" role="search">
                <input type="hidden" name="kind" value="{{ $kind }}">
                <label class="control-field filter-search">
                    <span class="sr-only">Search this library</span>
                    <input name="q" type="search" value="{{ request('q') }}" placeholder="Search this library">
                </label>
                <label class="checkbox-row">
                    <input type="checkbox" name="favorites" value="1" @checked(request()->boolean('favorites'))>
                    <span>Favorites</span>
                </label>
                <button class="button button-primary" type="submit">Filter</button>
            </form>
        </header>

        <nav class="filter-tabs" aria-label="Library type">
            <a class="{{ $kind === 'video' ? 'is-active' : '' }}" href="{{ route('media.index', ['kind' => 'video']) }}">Movies & TV</a>
            <a class="{{ $kind === 'music' ? 'is-active' : '' }}" href="{{ route('media.index', ['kind' => 'music']) }}">Music</a>
        </nav>

        @if ($items->isEmpty())
            <div class="empty-state">
                <h2>No matching media</h2>
                <p>@if(auth()->user()->isAdmin()) Add and scan a source under Media sources. @else Ask an administrator to scan a library. @endif</p>
            </div>
        @else
            @php
                $series = $kind === 'video'
                    ? $items->filter(fn ($item) => ($item->metadata['kind'] ?? '') === 'episode')
                        ->groupBy(fn ($item) => $item->metadata['series_title'] ?? 'TV')
                    : collect();
            @endphp

            @if ($series->isNotEmpty())
                <section class="library-section" aria-labelledby="series-heading">
                    <div class="shelf-heading"><h2 id="series-heading">TV series</h2></div>
                    <div class="library-grid">
                        @foreach ($series as $name => $episodes)
                            <a class="media-card" href="{{ route('media.index', ['kind' => 'video', 'series' => $name]) }}">
                                <div class="media-poster">
                                    @if (($episodes->first()->metadata['poster_cached'] ?? false))
                                        <img src="{{ route('media.artwork', [$episodes->first(), 'poster']) }}" alt="">
                                    @else
                                        <span class="media-placeholder">{{ mb_strtoupper(mb_substr($name, 0, 2)) }}</span>
                                    @endif
                                    <span class="source-badge">{{ $episodes->count() }} episodes</span>
                                </div>
                                <div class="card-copy">
                                    <h3>{{ $name }}</h3>
                                    <p>{{ $episodes->groupBy(fn ($episode) => $episode->metadata['season_number'] ?? 0)->count() }} seasons</p>
                                </div>
                            </a>
                        @endforeach
                    </div>
                </section>
            @endif

            <section class="library-section" aria-labelledby="items-heading">
                <div class="shelf-heading"><h2 id="items-heading">{{ $kind === 'music' ? 'Albums & tracks' : 'Movies & episodes' }}</h2></div>
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
                                    <span class="source-badge">{{ $item->requires_transcode ? 'Convert' : 'Direct' }}</span>
                                    @if ($item->progress)
                                        <span class="poster-progress" style="--progress: {{ min(100, ($item->progress->position_ms / max(1, $item->progress->duration_ms ?? 1)) * 100) }}%"></span>
                                    @endif
                                </div>
                                <div class="card-copy">
                                    <h3>{{ $item->title }}</h3>
                                    <p>
                                        {{ $item->metadata['artist'] ?? $item->metadata['year'] ?? strtoupper($item->container ?? $kind) }}
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
    </section>
@endsection
