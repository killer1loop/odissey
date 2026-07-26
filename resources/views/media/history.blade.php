@extends('layouts.app')

@section('title', 'History · Odissey')

@section('content')
    <section class="page-section">
        <header class="page-header">
            <div>
                <p class="eyebrow">Private to you</p>
                <h1>Viewing history</h1>
                <p>Your watch activity is not shared with other Odissey users.</p>
            </div>
        </header>

        <div class="admin-list">
            @forelse ($history as $entry)
                @if ($entry['item'])
                    <a class="admin-card history-row" href="{{ route('media.show', $entry['item']) }}">
                        <div>
                            <strong>{{ $entry['item']->title }}</strong>
                            <p>{{ floor($entry['watched_ms'] / 60000) }} minutes watched · {{ $entry['completed'] ? 'Completed' : 'In progress' }}</p>
                        </div>
                        <time>{{ \Carbon\Carbon::parse($entry['last_played_at'])->timezone(auth()->user()->timezone)->diffForHumans() }}</time>
                    </a>
                @endif
            @empty
                <div class="empty-state">
                    <h2>No playback yet</h2>
                    <p>Movies, episodes, and music you play will appear here.</p>
                </div>
            @endforelse
        </div>
    </section>
@endsection
