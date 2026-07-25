@extends('layouts.app')

@section('title', 'Video · Odissey')

@section('content')
    <section class="content-section" aria-labelledby="media-heading">
        <div class="section-heading">
            <div>
                <p class="eyebrow">Read-only sources</p>
                <h2 id="media-heading">Video test library</h2>
            </div>
            <span>Source files stay external; Odissey stores only catalog and playback state.</span>
        </div>

        @if ($items->isEmpty())
            <div class="panel">
                <h3>No test media is registered</h3>
                <p>
                    An administrator can explicitly generate short transient E2E fixtures with
                    <code>php artisan media:e2e:generate &lt;user&gt;</code>.
                </p>
            </div>
        @else
            <div class="media-shelf">
                @foreach ($items as $item)
                    <a class="source-card" href="{{ route('media.show', $item) }}">
                        <div class="source-art">
                            <svg aria-hidden="true" viewBox="0 0 64 64">
                                <rect x="8" y="14" width="48" height="36" rx="7"/>
                                <path d="m26 24 15 8-15 8z"/>
                            </svg>
                            <span class="source-badge">
                                {{ $item->requires_transcode ? 'FFmpeg HLS' : 'Direct play' }}
                            </span>
                        </div>
                        <div class="card-copy">
                            <h3>{{ $item->title }}</h3>
                            <p>
                                {{ strtoupper($item->container ?? 'video') }}
                                @if ($item->progress)
                                    · Resume at {{ floor($item->progress->position_ms / 1000) }}s
                                @endif
                            </p>
                        </div>
                    </a>
                @endforeach
            </div>
        @endif
    </section>
@endsection
