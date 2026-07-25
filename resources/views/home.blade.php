@extends('layouts.app')

@section('title', 'Odissey · Foundation')

@section('content')
    <section class="hero">
        <div class="hero-copy">
            <p class="eyebrow">Self-hosted media, without the media lock-in</p>
            <h1>Your media.<br><span>One fast interface.</span></h1>
            <p class="hero-summary">
                Odissey is being built as a server-rendered home for live TV, video, and music
                already stored in the places you control.
            </p>
            <div class="hero-actions">
                <a class="button button-primary" href="#roadmap">
                    Explore the roadmap
                    <svg aria-hidden="true" viewBox="0 0 24 24">
                        <path d="M5 12h14M13 6l6 6-6 6"/>
                    </svg>
                </a>
                <span class="button button-muted" aria-disabled="true">Setup unlocks in milestone 1</span>
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
            <span class="orbit-node node-music">Music</span>
        </div>
    </section>

    <section class="content-section" aria-labelledby="source-heading">
        <div class="section-heading">
            <div>
                <p class="eyebrow">Read-only by design</p>
                <h2 id="source-heading">Bring the sources you already use</h2>
            </div>
            <span>Metadata is indexed; source media stays in place.</span>
        </div>

        <div class="media-shelf">
            <article class="source-card source-card-iptv">
                <div class="source-art">
                    <svg aria-hidden="true" viewBox="0 0 64 64">
                        <rect x="8" y="14" width="48" height="36" rx="7"/>
                        <path d="M22 8h20M32 8v6M20 26h11M20 35h24M20 43h17"/>
                    </svg>
                    <span class="source-badge">Milestone 2</span>
                </div>
                <div class="card-copy">
                    <h3>IPTV providers</h3>
                    <p>EPG, channel groups, favorites, and a fast live guide.</p>
                </div>
            </article>

            <article class="source-card source-card-local">
                <div class="source-art">
                    <svg aria-hidden="true" viewBox="0 0 64 64">
                        <rect x="10" y="12" width="44" height="40" rx="8"/>
                        <circle cx="32" cy="32" r="12"/>
                        <circle cx="32" cy="32" r="3"/>
                    </svg>
                    <span class="source-badge">Read-only</span>
                </div>
                <div class="card-copy">
                    <h3>Local mounts</h3>
                    <p>Browse media mounted into the container without copying it.</p>
                </div>
            </article>

            <article class="source-card source-card-s3">
                <div class="source-art">
                    <svg aria-hidden="true" viewBox="0 0 64 64">
                        <path d="M19 48h29a11 11 0 0 0 1-22 17 17 0 0 0-32-3 13 13 0 0 0 2 25z"/>
                        <path d="M25 37h14M32 30v14"/>
                    </svg>
                    <span class="source-badge">S3-compatible</span>
                </div>
                <div class="card-copy">
                    <h3>Object storage</h3>
                    <p>Use scoped, read-only credentials and short-lived playback URLs.</p>
                </div>
            </article>

            <article class="source-card source-card-webdav">
                <div class="source-art">
                    <svg aria-hidden="true" viewBox="0 0 64 64">
                        <path d="M12 25h40v27H12z"/>
                        <path d="M12 25l8-13h24l8 13M24 37h16M24 44h10"/>
                    </svg>
                    <span class="source-badge">Capability-tested</span>
                </div>
                <div class="card-copy">
                    <h3>WebDAV shares</h3>
                    <p>Validate range support before enabling seekable playback.</p>
                </div>
            </article>
        </div>
    </section>

    <section class="content-section split-section">
        <div class="panel" aria-labelledby="user-state-heading">
            <div class="panel-heading">
                <div>
                    <p class="eyebrow">Independent profiles</p>
                    <h2 id="user-state-heading">Every viewer keeps their place</h2>
                </div>
                <div class="avatar-stack" aria-label="Example user profiles">
                    <span>A</span><span>M</span><span>S</span>
                </div>
            </div>
            <div class="progress-preview">
                <div class="progress-art" aria-hidden="true">
                    <span class="play-glyph">▶</span>
                </div>
                <div class="progress-copy">
                    <strong>Continue watching</strong>
                    <span>Resume positions, history, and favorites are scoped to each user.</span>
                    <div class="progress-track" aria-hidden="true"><span></span></div>
                    <small>42 minutes remaining</small>
                </div>
            </div>
        </div>

        <div class="panel status-panel" aria-labelledby="runtime-heading">
            <div class="panel-heading">
                <div>
                    <p class="eyebrow">Live server fragment</p>
                    <h2 id="runtime-heading">Foundation status</h2>
                </div>
                <span class="live-dot">HTMX</span>
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

    <section id="roadmap" class="content-section roadmap-section" aria-labelledby="roadmap-heading">
        <div class="section-heading">
            <div>
                <p class="eyebrow">Build sequence</p>
                <h2 id="roadmap-heading">IPTV first, then the wider library</h2>
            </div>
            <span>Each milestone has a testable release gate.</span>
        </div>

        <ol class="roadmap">
            <li class="is-current">
                <span class="roadmap-number">01</span>
                <div>
                    <strong>Secure foundation</strong>
                    <p>First-admin bootstrap, multi-user auth, policies, container lifecycle, and backups.</p>
                </div>
                <span class="roadmap-state">Next</span>
            </li>
            <li>
                <span class="roadmap-number">02</span>
                <div>
                    <strong>IPTV catalog and EPG</strong>
                    <p>Provider onboarding, background sync, channel groups, guide, search, and favorites.</p>
                </div>
                <span class="roadmap-state">Planned</span>
            </li>
            <li>
                <span class="roadmap-number">03</span>
                <div>
                    <strong>Live playback</strong>
                    <p>Credential-safe stream sessions, direct play, remuxing, history, and recovery.</p>
                </div>
                <span class="roadmap-state">Planned</span>
            </li>
            <li>
                <span class="roadmap-number">04</span>
                <div>
                    <strong>Local, S3, and WebDAV</strong>
                    <p>Read-only adapters, indexed catalogs, music, video, and per-user resume state.</p>
                </div>
                <span class="roadmap-state">Planned</span>
            </li>
            <li>
                <span class="roadmap-number">05</span>
                <div>
                    <strong>FFmpeg HLS</strong>
                    <p>Bounded transcoding, transient segments, cleanup, concurrency limits, and graceful shutdown.</p>
                </div>
                <span class="roadmap-state">Planned</span>
            </li>
        </ol>
    </section>

    <footer class="app-footer">
        <span>Odissey is an independent open-source project inspired by familiar media-library patterns.</span>
        <span>Source media remains external.</span>
    </footer>
@endsection
