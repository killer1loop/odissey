@extends('layouts.app')

@section('title', 'Home · Odissey')

@section('content')
    <section class="hero dashboard-hero">
        <div class="hero-copy">
            <p class="eyebrow">Your self-hosted media home</p>
            <h1>Welcome back,<br><span>{{ auth()->user()->name }}.</span></h1>
            <p class="hero-summary">
                Browse live television or continue with video already connected to this server.
                Source media stays outside Odissey; only encrypted settings, catalog metadata,
                and your private viewing state are kept here.
            </p>
            <div class="hero-actions">
                <a class="button button-primary" href="{{ route('iptv.channels.index') }}">
                    Watch live TV
                    <svg aria-hidden="true" viewBox="0 0 24 24">
                        <path d="M5 12h14M13 6l6 6-6 6"/>
                    </svg>
                </a>
                <a class="button button-muted" href="{{ route('media.index') }}">Open video library</a>
            </div>
        </div>

        <div class="hero-orbit" aria-hidden="true">
            <div class="orbit orbit-one"></div>
            <div class="orbit orbit-two"></div>
            <div class="orbit-core">
                <span class="brand-mark brand-mark-large"><span></span></span>
            </div>
            <span class="orbit-node node-live">Live</span>
            <span class="orbit-node node-video">Video</span>
            <span class="orbit-node node-music">HLS</span>
        </div>
    </section>

    @if (session('status'))
        <div class="content-section dashboard-notice">
            <p class="notice-success" role="status">{{ session('status') }}</p>
        </div>
    @endif

    <section class="content-section dashboard-section" aria-labelledby="browse-heading">
        <div class="section-heading">
            <div>
                <p class="eyebrow">Browse</p>
                <h2 id="browse-heading">Choose what to watch</h2>
            </div>
            <span>Fast server-rendered screens, progressively enhanced for playback and filtering.</span>
        </div>

        <div class="media-shelf">
            <a class="source-card source-card-iptv" href="{{ route('iptv.channels.index') }}">
                <div class="source-art">
                    <svg aria-hidden="true" viewBox="0 0 64 64">
                        <rect x="8" y="14" width="48" height="36" rx="7"/>
                        <path d="M22 8h20M32 8v6M20 26h11M20 35h24M20 43h17"/>
                    </svg>
                    <span class="source-badge">Live now</span>
                </div>
                <div class="card-copy">
                    <h3>Live TV</h3>
                    <p>Groups, current guide data, channel search, and personal favorites.</p>
                </div>
            </a>

            <a class="source-card source-card-local" href="{{ route('media.index') }}">
                <div class="source-art">
                    <svg aria-hidden="true" viewBox="0 0 64 64">
                        <rect x="9" y="13" width="46" height="38" rx="7"/>
                        <path d="m26 24 15 8-15 8z"/>
                    </svg>
                    <span class="source-badge">Direct + HLS</span>
                </div>
                <div class="card-copy">
                    <h3>Video</h3>
                    <p>Seekable direct play and temporary H.264/AAC conversion through FFmpeg.</p>
                </div>
            </a>

            <a class="source-card source-card-webdav" href="{{ route('iptv.channels.index', ['favorites' => 1]) }}">
                <div class="source-art">
                    <svg aria-hidden="true" viewBox="0 0 64 64">
                        <path d="m32 10 6.4 13 14.4 2.1-10.4 10.1 2.5 14.3L32 42.8l-12.9 6.7 2.5-14.3L11.2 25.1 25.6 23z"/>
                    </svg>
                    <span class="source-badge">Per user</span>
                </div>
                <div class="card-copy">
                    <h3>Favorites</h3>
                    <p>Your saved live channels remain separate from every other account.</p>
                </div>
            </a>

            @if (auth()->user()->isAdmin())
                <a class="source-card source-card-s3" href="{{ route('iptv.admin.providers.index') }}">
                    <div class="source-art">
                        <svg aria-hidden="true" viewBox="0 0 64 64">
                            <path d="M12 20h40M17 32h30M22 44h20"/>
                            <circle cx="17" cy="20" r="3"/>
                            <circle cx="47" cy="32" r="3"/>
                            <circle cx="27" cy="44" r="3"/>
                        </svg>
                        <span class="source-badge">Administrator</span>
                    </div>
                    <div class="card-copy">
                        <h3>IPTV providers</h3>
                        <p>Add encrypted provider details, sync channels, and refresh the guide.</p>
                    </div>
                </a>
            @else
                <article class="source-card source-card-s3">
                    <div class="source-art">
                        <svg aria-hidden="true" viewBox="0 0 64 64">
                            <circle cx="32" cy="32" r="20"/>
                            <path d="M32 20v13l9 5"/>
                        </svg>
                        <span class="source-badge">Private</span>
                    </div>
                    <div class="card-copy">
                        <h3>Viewing state</h3>
                        <p>Resume positions and playback history are scoped only to this account.</p>
                    </div>
                </article>
            @endif
        </div>
    </section>

    <section class="content-section split-section dashboard-panels">
        <div class="panel" aria-labelledby="privacy-heading">
            <div class="panel-heading">
                <div>
                    <p class="eyebrow">Private by default</p>
                    <h2 id="privacy-heading">Your place is yours</h2>
                </div>
                <div class="avatar-stack" aria-label="Independent user profiles">
                    <span>{{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}</span>
                    <span>+</span>
                </div>
            </div>
            <p class="panel-copy">
                Odissey records resume position and playback events per signed-in user.
                IPTV favorites are independent, even when accounts share the same provider.
            </p>
        </div>

        <div class="panel status-panel" aria-labelledby="runtime-heading">
            <div class="panel-heading">
                <div>
                    <p class="eyebrow">Server runtime</p>
                    <h2 id="runtime-heading">Ready to stream</h2>
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
                <p class="status-loading">Checking the application runtime…</p>
            </div>
        </div>
    </section>

    <footer class="app-footer">
        <span>Odissey is an independent open-source project inspired by familiar media-library patterns.</span>
        <span>Source media remains external.</span>
    </footer>
@endsection
