@extends('layouts.app')

@section('title', $session->channel->name.' · Live TV · Odissey')

@section('content')
    @include('iptv.styles')

    <section class="iptv-page">
        <header class="iptv-header">
            <div>
                <p class="eyebrow">{{ $session->channel->group?->name ?? 'Live TV' }}</p>
                <h1>{{ $session->channel->name }}</h1>
                @if ($programs->first())
                    <p>Now: {{ $programs->first()->title }}</p>
                @else
                    <p>Live stream</p>
                @endif
            </div>
            <a class="button button-muted" href="{{ route('iptv.channels.index') }}">Back to channels</a>
        </header>

        <div
            class="iptv-player"
            data-iptv-player
            data-manifest-url="{{ route('iptv.playback.manifest', $session) }}"
        >
            <video controls playsinline preload="metadata" aria-label="{{ $session->channel->name }} live stream"></video>
            <p class="iptv-player-status" data-iptv-player-status role="status" aria-live="polite">Connecting to live stream…</p>
        </div>
    </section>
@endsection
