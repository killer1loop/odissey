@php
    $signedInUser = auth()->user();
    $avatarLetter = mb_strtoupper(mb_substr($signedInUser?->name ?? 'O', 0, 1));
    $routeMedia = isset($item) && $item instanceof \App\Models\MediaItem ? $item : null;
    $musicIsActive = (request()->routeIs('media.index') && request('kind') === 'music')
        || (request()->routeIs('media.show') && $routeMedia?->media_kind === 'music');
    $tvIsActive = (request()->routeIs('media.index') && request('library') === 'tv')
        || (request()->routeIs('media.show') && ($routeMedia?->metadata['kind'] ?? null) === 'episode');
    $historyIsActive = request()->routeIs('media.history');
    $moviesAreActive = request()->routeIs('media.*')
        && ! request()->routeIs('media.admin.*', 'media.history')
        && ! $musicIsActive
        && ! $tvIsActive;
    $videoIsActive = $moviesAreActive || $tvIsActive;
    $favoritesAreActive = request()->routeIs('iptv.channels.index') && request()->boolean('favorites');
    $liveTvIsActive = request()->routeIs('iptv.*') && ! request()->routeIs('iptv.admin.*') && ! $favoritesAreActive;
    $mediaSourcesAreActive = request()->routeIs('media.admin.sources.*');
    $integrationsAreActive = request()->routeIs('media.admin.integrations.*');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="color-scheme" content="dark">
        <meta name="theme-color" content="#05070c">

        <title>@yield('title', config('app.name'))</title>
        <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="@yield('body-class')" hx-boost="true">
        <a class="skip-link" href="#main-content">Skip to content</a>

        <div class="app-shell" data-page-body-class="@yield('body-class')">
            <aside class="sidebar" aria-label="Primary navigation">
                <a class="brand" href="{{ route('home') }}" aria-label="Odissey home">
                    <span class="brand-mark" aria-hidden="true">
                        <span></span>
                    </span>
                    <span>Odissey</span>
                </a>

                <nav class="primary-nav">
                    <p class="nav-label">Browse</p>
                    <a
                        class="nav-item {{ request()->routeIs('home') ? 'is-active' : '' }}"
                        href="{{ route('home') }}"
                        @if (request()->routeIs('home')) aria-current="page" @endif
                    >
                        <svg aria-hidden="true" viewBox="0 0 24 24">
                            <path d="M3 10.5 12 3l9 7.5v9a1.5 1.5 0 0 1-1.5 1.5h-15A1.5 1.5 0 0 1 3 19.5z"/>
                            <path d="M9 21v-6h6v6"/>
                        </svg>
                        <span>Home</span>
                    </a>
                    <a
                        class="nav-item {{ $moviesAreActive ? 'is-active' : '' }}"
                        href="{{ route('media.index', ['kind' => 'video', 'library' => 'movies']) }}"
                        @if ($moviesAreActive) aria-current="page" @endif
                    >
                        <svg aria-hidden="true" viewBox="0 0 24 24">
                            <rect x="3" y="5" width="18" height="14" rx="2"/>
                            <path d="m9 10 5 3-5 3z"/>
                        </svg>
                        <span>Movies</span>
                    </a>
                    <a
                        class="nav-item {{ $tvIsActive ? 'is-active' : '' }}"
                        href="{{ route('media.index', ['kind' => 'video', 'library' => 'tv']) }}"
                        @if ($tvIsActive) aria-current="page" @endif
                    >
                        <svg aria-hidden="true" viewBox="0 0 24 24">
                            <rect x="3" y="5" width="18" height="14" rx="2"/>
                            <path d="M8 3h8M12 3v2M7 10h4M7 14h7"/>
                        </svg>
                        <span>Series</span>
                    </a>
                    <a class="nav-item {{ $musicIsActive ? 'is-active' : '' }}" href="{{ route('media.index',['kind'=>'music']) }}" @if ($musicIsActive) aria-current="page" @endif>
                        <svg aria-hidden="true" viewBox="0 0 24 24">
                            <path d="M9 18V5l10-2v13"/>
                            <circle cx="6" cy="18" r="3"/>
                            <circle cx="16" cy="16" r="3"/>
                        </svg>
                        <span>Music</span>
                    </a>
                    <a
                        class="nav-item {{ $liveTvIsActive ? 'is-active' : '' }}"
                        href="{{ route('iptv.channels.index') }}"
                        @if ($liveTvIsActive) aria-current="page" @endif
                    >
                        <svg aria-hidden="true" viewBox="0 0 24 24">
                            <rect x="3" y="6" width="18" height="13" rx="2"/>
                            <path d="M8 3h8M12 3v3M7 11h4M7 15h7"/>
                        </svg>
                        <span>Live TV</span>
                    </a>
                    <p class="nav-label">Your media</p>
                    <a
                        class="nav-item {{ $favoritesAreActive ? 'is-active' : '' }}"
                        href="{{ route('iptv.channels.index', ['favorites' => 1]) }}"
                        @if ($favoritesAreActive) aria-current="page" @endif
                    >
                        <svg aria-hidden="true" viewBox="0 0 24 24">
                            <path d="m12 3 2.7 5.5 6.1.9-4.4 4.3 1 6.1-5.4-2.9-5.4 2.9 1-6.1-4.4-4.3 6.1-.9z"/>
                        </svg>
                        <span>Favorites</span>
                    </a>
                    <a class="nav-item {{ $historyIsActive ? 'is-active' : '' }}" href="{{ route('media.history') }}" @if ($historyIsActive) aria-current="page" @endif>
                        <svg aria-hidden="true" viewBox="0 0 24 24">
                            <circle cx="12" cy="12" r="9"/>
                            <path d="M12 7v5l3.5 2"/>
                        </svg>
                        <span>History</span>
                    </a>

                    @if ($signedInUser?->isAdmin())
                        <p class="nav-label">Administration</p>
                        <a
                            class="nav-item {{ request()->routeIs('iptv.admin.*') ? 'is-active' : '' }}"
                            href="{{ route('iptv.admin.providers.index') }}"
                            @if (request()->routeIs('iptv.admin.*')) aria-current="page" @endif
                        >
                            <svg aria-hidden="true" viewBox="0 0 24 24">
                                <path d="M4 7h16M7 12h10M9 17h6"/>
                            </svg>
                            <span>IPTV providers</span>
                        </a>
                        <a
                            class="nav-item {{ $mediaSourcesAreActive ? 'is-active' : '' }}"
                            href="{{ route('media.admin.sources.index') }}"
                            @if ($mediaSourcesAreActive) aria-current="page" @endif
                        >
                            <svg aria-hidden="true" viewBox="0 0 24 24">
                                <ellipse cx="12" cy="6" rx="8" ry="3"/>
                                <path d="M4 6v6c0 1.7 3.6 3 8 3s8-1.3 8-3V6M4 12v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/>
                            </svg>
                            <span>Media sources</span>
                        </a>
                        <a
                            class="nav-item {{ $integrationsAreActive ? 'is-active' : '' }}"
                            href="{{ route('media.admin.integrations.edit') }}"
                            @if ($integrationsAreActive) aria-current="page" @endif
                        >
                            <svg aria-hidden="true" viewBox="0 0 24 24">
                                <path d="M4 7h10M4 12h16M10 17h10"/>
                                <circle cx="17" cy="7" r="2"/>
                                <circle cx="7" cy="17" r="2"/>
                            </svg>
                            <span>Metadata & captions</span>
                        </a>
                        <a
                            class="nav-item {{ request()->routeIs('admin.users.*') ? 'is-active' : '' }}"
                            href="{{ route('admin.users.index') }}"
                            @if (request()->routeIs('admin.users.*')) aria-current="page" @endif
                        >
                            <svg aria-hidden="true" viewBox="0 0 24 24">
                                <circle cx="9" cy="8" r="3"/>
                                <path d="M3.5 20a5.5 5.5 0 0 1 11 0M16 11h5M18.5 8.5v5"/>
                            </svg>
                            <span>Users</span>
                        </a>
                    @endif
                </nav>

                <div class="sidebar-footer">
                    <div class="avatar" aria-hidden="true">{{ $avatarLetter }}</div>
                    <div class="account-copy">
                        <strong><a href="{{ route('preferences.edit') }}">{{ $signedInUser?->name }}</a></strong>
                        <span>{{ $signedInUser?->isAdmin() ? 'Administrator' : 'Viewer' }}</span>
                    </div>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button class="logout-button" type="submit" aria-label="Sign out">
                            <svg aria-hidden="true" viewBox="0 0 24 24">
                                <path d="M10 5H5v14h5M14 8l4 4-4 4M8 12h10"/>
                            </svg>
                        </button>
                    </form>
                </div>
            </aside>

            <div class="workspace">
                <header class="topbar">
                    @hasSection('topbar')
                        @yield('topbar')
                    @else
                    <div class="mobile-brand">
                        <span class="brand-mark" aria-hidden="true"><span></span></span>
                        <span>Odissey</span>
                    </div>

                    <form class="search-shell" method="GET" action="{{ route('iptv.channels.index') }}" role="search">
                        <svg aria-hidden="true" viewBox="0 0 24 24">
                            <circle cx="11" cy="11" r="6.5"/>
                            <path d="m16 16 4 4"/>
                        </svg>
                        <label class="sr-only" for="global-search">Search live channels</label>
                        <input
                            id="global-search"
                            name="q"
                            type="search"
                            maxlength="100"
                            value="{{ request()->routeIs('iptv.channels.index') ? request('q') : '' }}"
                            placeholder="Search live channels"
                        >
                    </form>

                    <div class="topbar-actions">
                        <nav class="topbar-quick-links" aria-label="Quick links">
                            <a class="{{ $moviesAreActive ? 'is-active' : '' }}" href="{{ route('media.index', ['kind' => 'video', 'library' => 'movies']) }}">Movies</a>
                            <a class="{{ $tvIsActive ? 'is-active' : '' }}" href="{{ route('media.index', ['kind' => 'video', 'library' => 'tv']) }}">Series</a>
                            <a class="{{ $liveTvIsActive ? 'is-active' : '' }}" href="{{ route('iptv.channels.index') }}">Live TV</a>
                        </nav>
                    </div>

                    <details class="mobile-menu">
                        <summary aria-label="Open navigation menu">
                            <svg aria-hidden="true" viewBox="0 0 24 24">
                                <path d="M4 7h16M4 12h16M4 17h16"/>
                            </svg>
                        </summary>
                        <nav class="mobile-menu-panel" aria-label="More navigation">
                            <div class="mobile-menu-account">
                                <span class="avatar" aria-hidden="true">{{ $avatarLetter }}</span>
                                <span><strong>{{ $signedInUser?->name }}</strong><small>{{ $signedInUser?->isAdmin() ? 'Administrator' : 'Viewer' }}</small></span>
                            </div>
                            <a class="{{ $favoritesAreActive ? 'is-active' : '' }}" href="{{ route('iptv.channels.index', ['favorites' => 1]) }}" @if($favoritesAreActive) aria-current="page" @endif>Favorites</a>
                            <a class="{{ $historyIsActive ? 'is-active' : '' }}" href="{{ route('media.history') }}" @if($historyIsActive) aria-current="page" @endif>Viewing history</a>
                            <a class="{{ request()->routeIs('preferences.*') ? 'is-active' : '' }}" href="{{ route('preferences.edit') }}" @if(request()->routeIs('preferences.*')) aria-current="page" @endif>Playback preferences</a>
                            @if ($signedInUser?->isAdmin())
                                <span class="mobile-menu-label">Administration</span>
                                <a class="{{ request()->routeIs('iptv.admin.*') ? 'is-active' : '' }}" href="{{ route('iptv.admin.providers.index') }}" @if(request()->routeIs('iptv.admin.*')) aria-current="page" @endif>IPTV providers</a>
                                <a class="{{ $mediaSourcesAreActive ? 'is-active' : '' }}" href="{{ route('media.admin.sources.index') }}" @if($mediaSourcesAreActive) aria-current="page" @endif>Media sources</a>
                                <a class="{{ $integrationsAreActive ? 'is-active' : '' }}" href="{{ route('media.admin.integrations.edit') }}" @if($integrationsAreActive) aria-current="page" @endif>Metadata & captions</a>
                                <a class="{{ request()->routeIs('admin.users.*') ? 'is-active' : '' }}" href="{{ route('admin.users.index') }}" @if(request()->routeIs('admin.users.*')) aria-current="page" @endif>Users</a>
                            @endif
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit">Sign out</button>
                            </form>
                        </nav>
                    </details>
                    @endif
                </header>

                <main id="main-content" tabindex="-1">
                    @yield('content')
                </main>
            </div>
        </div>

        <nav class="mobile-nav" aria-label="Mobile navigation">
            <a
                class="{{ request()->routeIs('home') ? 'is-active' : '' }}"
                href="{{ route('home') }}"
                @if (request()->routeIs('home')) aria-current="page" @endif
            >
                <svg aria-hidden="true" viewBox="0 0 24 24">
                    <path d="M3 10.5 12 3l9 7.5v9a1.5 1.5 0 0 1-1.5 1.5h-15A1.5 1.5 0 0 1 3 19.5z"/>
                    <path d="M9 21v-6h6v6"/>
                </svg>
                <span>Home</span>
            </a>
            <a
                class="{{ $moviesAreActive ? 'is-active' : '' }}"
                href="{{ route('media.index', ['kind' => 'video', 'library' => 'movies']) }}"
                @if ($moviesAreActive) aria-current="page" @endif
            >
                <svg aria-hidden="true" viewBox="0 0 24 24">
                    <rect x="3" y="5" width="18" height="14" rx="2"/>
                    <path d="m9 10 5 3-5 3z"/>
                </svg>
                <span>Movies</span>
            </a>
            <a
                class="{{ $tvIsActive ? 'is-active' : '' }}"
                href="{{ route('media.index', ['kind' => 'video', 'library' => 'tv']) }}"
                @if ($tvIsActive) aria-current="page" @endif
            >
                <svg aria-hidden="true" viewBox="0 0 24 24">
                    <rect x="3" y="5" width="18" height="14" rx="2"/>
                    <path d="M8 3h8M12 3v2M7 10h4M7 14h7"/>
                </svg>
                <span>Series</span>
            </a>
            <a
                class="{{ $musicIsActive ? 'is-active' : '' }}"
                href="{{ route('media.index', ['kind' => 'music']) }}"
                @if ($musicIsActive) aria-current="page" @endif
            >
                <svg aria-hidden="true" viewBox="0 0 24 24">
                    <path d="M9 18V5l10-2v13"/>
                    <circle cx="6" cy="18" r="3"/>
                    <circle cx="16" cy="16" r="3"/>
                </svg>
                <span>Music</span>
            </a>
            <a
                class="{{ $liveTvIsActive || $favoritesAreActive ? 'is-active' : '' }}"
                href="{{ route('iptv.channels.index') }}"
                @if ($liveTvIsActive || $favoritesAreActive) aria-current="page" @endif
            >
                <svg aria-hidden="true" viewBox="0 0 24 24">
                    <rect x="3" y="6" width="18" height="13" rx="2"/>
                    <path d="M8 3h8M12 3v3"/>
                </svg>
                <span>Live TV</span>
            </a>
        </nav>

        <div class="request-indicator" aria-hidden="true"></div>
        <p class="sr-only" role="status" aria-live="polite" data-request-announcer></p>
    </body>
</html>
