@extends('layouts.app')

@section('title', $item->title.' · Odissey')

@section('content')
    <section class="media-detail" aria-labelledby="player-heading">
        @if (($item->metadata['backdrop_cached'] ?? false))
            <div class="media-backdrop" style="background-image: url('{{ route('media.artwork', [$item, 'backdrop']) }}')" aria-hidden="true"></div>
        @endif
        <div class="media-detail-content">
        <header class="page-header media-detail-header">
            <div>
                <p class="eyebrow">{{ $item->requires_transcode ? 'Transient HLS transcode' : 'Direct play' }}</p>
                <h1 id="player-heading">{{ $item->title }}</h1>
                @if($item->metadata['year'] ?? null)<p>{{ $item->metadata['year'] }} · {{ implode(' · ', $item->metadata['genres'] ?? []) }} @if($item->metadata['rating'] ?? null) · ★ {{ number_format($item->metadata['rating'],1) }} @endif</p>@endif
            </div>
            <a class="button button-muted" href="{{ route('media.index', array_filter([
                'kind' => $item->media_kind,
                'library' => ($item->metadata['kind'] ?? '') === 'episode' ? 'tv' : (($item->media_kind === 'video') ? 'movies' : null),
                'series' => $item->metadata['series_title'] ?? null,
                'source' => $item->media_source_id,
            ])) }}">Back to {{ ($item->metadata['kind'] ?? '') === 'episode' ? 'episodes' : 'library' }}</a>
        </header>

        <div class="media-player-panel">
            @if($item->media_kind === 'video')
            <form class="caption-action" method="POST" action="{{ route('media.captions.fetch',$item) }}">
                @csrf
                <button class="button button-muted" type="submit">Find captions</button>
                <span>{{ $item->subtitles->count() }} downloaded track(s); embedded tracks are detected automatically.</span>
            </form>
            @endif
            @if ($item->requires_transcode)
                @include('media.partials.transcode-status', [
                    'item' => $item,
                    'session' => $session,
                    'progress' => $progress,
                ])
            @else
                @include('media.partials.player', [
                    'item' => $item,
                    'progress' => $progress,
                    'sourceType' => 'direct',
                    'sourceUrl' => route('media.direct', $item),
                ])
            @endif
        </div>

        <div class="panel media-info-panel">
            @if($item->metadata['summary'] ?? null)<p>{{ $item->metadata['summary'] }}</p>@endif
            @if($item->metadata['series_title'] ?? null)<p><strong>{{ $item->metadata['series_title'] }}</strong> · Season {{ $item->metadata['season_number'] }} · Episode {{ $item->metadata['episode_number'] }}</p>@endif
            @if($item->metadata['artist'] ?? null)<p><strong>{{ $item->metadata['artist'] }}</strong>@if($item->metadata['album'] ?? null) · {{ $item->metadata['album'] }} @endif</p>@endif
            @if($item->metadata['cast'] ?? null)<p>Cast: {{ implode(', ', $item->metadata['cast']) }}</p>@endif
            @if(($item->metadata['provider'] ?? null) === 'tmdb')<p class="eyebrow">Metadata supplied by TMDB. Odissey is not endorsed or certified by TMDB.</p>@endif
            @if(($item->metadata['provider'] ?? null) === 'tvmaze')<p class="eyebrow">TV metadata supplied by TVmaze.</p>@endif
            <p class="eyebrow">Playback details</p>
            <p>
                {{ strtoupper($item->container ?? 'unknown container') }}
                · {{ strtoupper($item->video_codec ?? 'unknown video') }}
                · {{ strtoupper($item->audio_codec ?? 'unknown audio') }}
            </p>
            <p>
                Progress and history are private to the signed-in user. Transcode output is
                temporary and every manifest and segment request requires authentication.
            </p>
        </div>
        </div>
    </section>
@endsection
