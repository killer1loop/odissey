@extends('layouts.app')

@section('title', 'Home · Odissey')

@section('content')
    <section class="page-section dashboard-page" aria-labelledby="home-heading">
        <header class="page-header">
            <div>
                <p class="eyebrow">Home</p>
                <h1 id="home-heading">Welcome back, {{ auth()->user()->name }}</h1>
            </div>
            <p class="page-summary">Continue watching or open one of your connected libraries.</p>
        </header>

        @if (session('status'))
            <p class="notice notice-success" role="status">{{ session('status') }}</p>
        @endif

        <section class="dashboard-section" aria-labelledby="browse-heading">
            <div class="shelf-heading">
                <h2 id="browse-heading">Browse</h2>
            </div>

            <div class="media-shelf dashboard-shelf">
                <a class="source-card source-card-iptv" href="{{ route('iptv.channels.index') }}">
                    <div class="source-art">
                        <svg aria-hidden="true" viewBox="0 0 64 64">
                            <rect x="8" y="14" width="48" height="36" rx="2"/>
                            <path d="M22 8h20M32 8v6M20 26h11M20 35h24M20 43h17"/>
                        </svg>
                        <span class="source-badge">Live</span>
                    </div>
                    <div class="card-copy">
                        <h3>Live TV</h3>
                        <p>Channels, groups, favorites, and programme guide</p>
                    </div>
                </a>

                <a class="source-card source-card-local" href="{{ route('media.index') }}">
                    <div class="source-art">
                        <svg aria-hidden="true" viewBox="0 0 64 64">
                            <rect x="9" y="13" width="46" height="38" rx="2"/>
                            <path d="m26 24 15 8-15 8z"/>
                        </svg>
                        <span class="source-badge">Direct + HLS</span>
                    </div>
                    <div class="card-copy">
                        <h3>Movies & TV</h3>
                        <p>Your connected video libraries</p>
                    </div>
                </a>

                <a class="source-card source-card-s3" href="{{ route('media.index', ['kind' => 'music']) }}">
                    <div class="source-art">
                        <svg aria-hidden="true" viewBox="0 0 64 64">
                            <path d="M25 48V15l25-5v32"/>
                            <circle cx="17" cy="48" r="8"/>
                            <circle cx="42" cy="42" r="8"/>
                        </svg>
                        <span class="source-badge">Music</span>
                    </div>
                    <div class="card-copy">
                        <h3>Music</h3>
                        <p>Albums and tracks from every source</p>
                    </div>
                </a>

                <a class="source-card source-card-webdav" href="{{ route('iptv.channels.index', ['favorites' => 1]) }}">
                    <div class="source-art">
                        <svg aria-hidden="true" viewBox="0 0 64 64">
                            <path d="m32 10 6.4 13 14.4 2.1-10.4 10.1 2.5 14.3L32 42.8l-12.9 6.7 2.5-14.3L11.2 25.1 25.6 23z"/>
                        </svg>
                        <span class="source-badge">Personal</span>
                    </div>
                    <div class="card-copy">
                        <h3>Favorites</h3>
                        <p>Your saved live channels</p>
                    </div>
                </a>
            </div>
        </section>

        <section class="dashboard-grid" aria-label="Odissey status">
            <div class="panel compact-panel">
                <div class="panel-heading">
                    <div>
                        <p class="eyebrow">Privacy</p>
                        <h2>Viewing state stays personal</h2>
                    </div>
                    <span class="avatar">{{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}</span>
                </div>
                <p>Resume positions, playback history, and IPTV favorites are kept separately for every account.</p>
            </div>

            <div class="panel compact-panel">
                <div class="panel-heading">
                    <div>
                        <p class="eyebrow">Runtime</p>
                        <h2>Ready to stream</h2>
                    </div>
                    <span class="live-dot">Online</span>
                </div>
                <div
                    id="foundation-status"
                    class="status-grid"
                    hx-get="{{ route('foundation-status') }}"
                    hx-trigger="load"
                    hx-swap="outerHTML"
                    aria-live="polite"
                >
                    <p class="status-loading">Checking server…</p>
                </div>
            </div>
        </section>
    </section>
@endsection
