@extends('layouts.app')

@section('title', 'Guide · Odissey')

@section('content')
    <section class="page-section">
        <header class="page-header">
            <div>
                <p class="eyebrow">Live TV</p>
                <h1>Programme guide</h1>
                <p>{{ $start->format('D j M, H:i') }}–{{ $end->format('H:i') }}</p>
            </div>
            <a class="button button-muted" href="{{ route('iptv.channels.index') }}">Channels</a>
        </header>

        <div class="guide-shell">
            <div class="guide-grid" role="grid" aria-label="Six-hour television guide">
                @foreach ($channels as $channel)
                    <div class="guide-row" role="row">
                        <div class="guide-channel" role="rowheader">
                            <strong>{{ $channel->name }}</strong>
                            <small>{{ $channel->group?->name }}</small>
                        </div>
                        <div class="guide-programs" role="gridcell">
                            @forelse ($channel->programs as $program)
                                @php($minutes = max(15, $program->starts_at->diffInMinutes($program->ends_at)))
                                <form method="POST" action="{{ route('iptv.playback.store', $channel) }}" style="--program-width: {{ $minutes * 3 }}px">
                                    @csrf
                                    <button type="submit" class="guide-program" title="{{ $program->description }}">
                                        <strong>{{ $program->title }}</strong>
                                        <small>{{ $program->starts_at->timezone(auth()->user()->timezone)->format('H:i') }}–{{ $program->ends_at->timezone(auth()->user()->timezone)->format('H:i') }}</small>
                                    </button>
                                </form>
                            @empty
                                <div class="guide-empty">No guide data</div>
                            @endforelse
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </section>
@endsection
