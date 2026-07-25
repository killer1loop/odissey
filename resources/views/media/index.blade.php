@extends('layouts.app')
@section('title', ($kind === 'music' ? 'Music' : 'Video').' · Odissey')
@section('content')
<section class="content-section" aria-labelledby="media-heading">
    <div class="section-heading"><div><p class="eyebrow">Your libraries</p><h2 id="media-heading">{{ $kind === 'music' ? 'Music' : 'Movies & TV' }}</h2></div>
    <form method="GET" role="search"><input type="hidden" name="kind" value="{{ $kind }}"><input name="q" type="search" value="{{ request('q') }}" placeholder="Search this library"><label><input type="checkbox" name="favorites" value="1" @checked(request()->boolean('favorites'))> Favorites</label><button class="button" type="submit">Filter</button></form></div>
    <nav class="filter-tabs"><a class="{{ $kind === 'video' ? 'is-active' : '' }}" href="{{ route('media.index', ['kind'=>'video']) }}">Movies & TV</a><a class="{{ $kind === 'music' ? 'is-active' : '' }}" href="{{ route('media.index', ['kind'=>'music']) }}">Music</a></nav>
    @if($items->isEmpty())
    <div class="panel"><h3>No matching media</h3><p>@if(auth()->user()->isAdmin()) Add and scan a source under Media sources. @else Ask an administrator to scan a library. @endif</p></div>
    @else
    @php($series = $kind === 'video' ? $items->filter(fn($i) => ($i->metadata['kind'] ?? '') === 'episode')->groupBy(fn($i) => $i->metadata['series_title'] ?? 'TV') : collect())
    @if($series->isNotEmpty())<h2>TV series</h2><div class="media-shelf">@foreach($series as $name => $episodes)<a class="source-card" href="{{ route('media.index',['kind'=>'video','series'=>$name]) }}"><div class="source-art">@if(($episodes->first()->metadata['poster_cached'] ?? false))<img src="{{ route('media.artwork', [$episodes->first(), 'poster']) }}" alt="">@endif<span class="source-badge">{{ $episodes->count() }} episodes</span></div><div class="card-copy"><h3>{{ $name }}</h3><p>{{ $episodes->groupBy(fn($e)=>$e->metadata['season_number'] ?? 0)->count() }} seasons</p></div></a>@endforeach</div>@endif
    <h2>{{ $kind === 'music' ? 'Albums & tracks' : 'Movies & episodes' }}</h2>
    <div class="media-shelf">@foreach($items as $item)
    <article class="source-card"><a href="{{ route('media.show', $item) }}"><div class="source-art">@if(($item->metadata['poster_cached'] ?? false))<img src="{{ route('media.artwork', [$item, 'poster']) }}" alt="">@else<svg aria-hidden="true" viewBox="0 0 64 64"><rect x="8" y="14" width="48" height="36" rx="7"/><path d="m26 24 15 8-15 8z"/></svg>@endif<span class="source-badge">{{ $item->requires_transcode ? 'Convert' : 'Direct' }}</span></div><div class="card-copy"><h3>{{ $item->title }}</h3><p>{{ $item->metadata['artist'] ?? $item->metadata['year'] ?? strtoupper($item->container ?? $kind) }}@if($item->progress) · Resume {{ floor($item->progress->position_ms/60000) }}m @endif</p></div></a>
    <form method="POST" action="{{ $item->favorites->isEmpty() ? route('media.favorites.store',$item) : route('media.favorites.destroy',$item) }}">@csrf @if($item->favorites->isNotEmpty()) @method('DELETE') @endif<button class="button" type="submit">{{ $item->favorites->isEmpty() ? '☆ Favorite' : '★ Favorited' }}</button></form></article>
    @endforeach</div>
    @endif
</section>
@endsection
