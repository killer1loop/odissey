@extends('layouts.app')

@section('title', $item->title.' · Odissey')

@section('content')
    <section class="content-section" aria-labelledby="player-heading">
        <div class="section-heading">
            <div>
                <p class="eyebrow">{{ $item->requires_transcode ? 'Transient HLS transcode' : 'Direct play' }}</p>
                <h2 id="player-heading">{{ $item->title }}</h2>
            </div>
            <a class="button button-muted" href="{{ route('media.index') }}">Back to video</a>
        </div>

        <div class="panel">
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
