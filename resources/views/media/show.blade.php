@extends('layouts.app')

@section('title', $item->title.' · Odissey')

@section('content')
    <section class="content-section" aria-labelledby="player-heading" @if(($item->metadata['backdrop_cached'] ?? false)) style="background-image:linear-gradient(rgba(10,12,16,.65),#0b0d11),url('{{ route('media.artwork',[$item,'backdrop']) }}');background-size:cover;background-position:center top" @endif>
        <div class="section-heading">
            <div>
                <p class="eyebrow">{{ $item->requires_transcode ? 'Transient HLS transcode' : 'Direct play' }}</p>
                <h2 id="player-heading">{{ $item->title }}</h2>
                @if($item->metadata['year'] ?? null)<p>{{ $item->metadata['year'] }} · {{ implode(' · ', $item->metadata['genres'] ?? []) }} @if($item->metadata['rating'] ?? null) · ★ {{ number_format($item->metadata['rating'],1) }} @endif</p>@endif
            </div>
            <a class="button button-muted" href="{{ route('media.index') }}">Back to video</a>
        </div>

        <div class="panel">
            @if($item->media_kind === 'video')
            <form method="POST" action="{{ route('media.captions.fetch',$item) }}">@csrf<button class="button" type="submit">Find captions</button> <span>{{ $item->subtitles->count() }} downloaded caption track(s); embedded tracks are detected automatically.</span></form>
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

        <div class="panel">
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
    </section>
@endsection
