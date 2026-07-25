@php
    $signedInUser = auth()->user();
    $avatarLetter = mb_strtoupper(mb_substr($signedInUser?->name ?? 'O', 0, 1));
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="color-scheme" content="dark">

        <title>@yield('title', config('app.name'))</title>

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body hx-boost="true">
        <a class="skip-link" href="#main-content">Skip to content</a>

        <div class="app-shell">
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
                        class="nav-item {{ request()->routeIs('media.*') ? 'is-active' : '' }}"
                        href="{{ route('media.index') }}"
                        @if (request()->routeIs('media.*')) aria-current="page" @endif
                    >
                        <svg aria-hidden="true" viewBox="0 0 24 24">
                            <rect x="3" y="5" width="18" height="14" rx="2"/>
                            <path d="m9 10 5 3-5 3z"/>
                        </svg>
                        <span>Video</span>
                    </a>
                    <a class="nav-item {{ request('kind') === 'music' ? 'is-active' : '' }}" href="{{ route('media.index',['kind'=>'music']) }}">
                        <svg aria-hidden="true" viewBox="0 0 24 24">
                            <path d="M9 18V5l10-2v13"/>
                            <circle cx="6" cy="18" r="3"/>
                            <circle cx="16" cy="16" r="3"/>
                        </svg>
                        <span>Music</span>
                    </a>
                    <a
                        class="nav-item {{ request()->routeIs('iptv.*') && ! request()->routeIs('iptv.admin.*') ? 'is-active' : '' }}"
                        href="{{ route('iptv.channels.index') }}"
                        @if (request()->routeIs('iptv.*') && ! request()->routeIs('iptv.admin.*')) aria-current="page" @endif
                    >
                        <svg aria-hidden="true" viewBox="0 0 24 24">
                            <rect x="3" y="6" width="18" height="13" rx="2"/>
                            <path d="M8 3h8M12 3v3M7 11h4M7 15h7"/>
                        </svg>
                        <span>Live TV</span>
                    </a>
                    <a class="nav-item {{ request()->routeIs('iptv.guide') ? 'is-active' : '' }}" href="{{ route('iptv.guide') }}"><span>Guide</span></a>

                    <p class="nav-label">Your media</p>
                    <a
                        class="nav-item {{ request()->boolean('favorites') ? 'is-active' : '' }}"
                        href="{{ route('iptv.channels.index', ['favorites' => 1]) }}"
                    >
                        <svg aria-hidden="true" viewBox="0 0 24 24">
                            <path d="m12 3 2.7 5.5 6.1.9-4.4 4.3 1 6.1-5.4-2.9-5.4 2.9 1-6.1-4.4-4.3 6.1-.9z"/>
                        </svg>
                        <span>Favorites</span>
                    </a>
                    <a class="nav-item {{ request()->routeIs('media.history') ? 'is-active' : '' }}" href="{{ route('media.history') }}">
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
                        <a class="nav-item {{ request()->routeIs('media.admin.*') ? 'is-active' : '' }}" href="{{ route('media.admin.sources.index') }}"><span>Media sources</span></a>
                        <a class="nav-item {{ request()->routeIs('media.admin.integrations.*') ? 'is-active' : '' }}" href="{{ route('media.admin.integrations.edit') }}"><span>Metadata & captions</span></a>
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
                        <button class="logout-button" type="submit" aria-label="Sign out">↗</button>
                    </form>
                </div>
            </aside>

            <div class="workspace">
                <header class="topbar">
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
                        <a class="icon-button" href="{{ route('media.index') }}" aria-label="Open video library">
                            <svg aria-hidden="true" viewBox="0 0 24 24">
                                <rect x="3" y="5" width="18" height="14" rx="2"/>
                                <path d="m9 10 5 3-5 3z"/>
                            </svg>
                        </a>
                        <span class="foundation-pill">Self-hosted</span>
                    </div>
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
                class="{{ request()->routeIs('media.*') ? 'is-active' : '' }}"
                href="{{ route('media.index') }}"
                @if (request()->routeIs('media.*')) aria-current="page" @endif
            >
                <svg aria-hidden="true" viewBox="0 0 24 24">
                    <rect x="3" y="5" width="18" height="14" rx="2"/>
                    <path d="m9 10 5 3-5 3z"/>
                </svg>
                <span>Video</span>
            </a>
            <a
                class="{{ request()->routeIs('iptv.*') && ! request()->boolean('favorites') ? 'is-active' : '' }}"
                href="{{ route('iptv.channels.index') }}"
            >
                <svg aria-hidden="true" viewBox="0 0 24 24">
                    <rect x="3" y="6" width="18" height="13" rx="2"/>
                    <path d="M8 3h8M12 3v3"/>
                </svg>
                <span>Live TV</span>
            </a>
            <a
                class="{{ request()->boolean('favorites') ? 'is-active' : '' }}"
                href="{{ route('iptv.channels.index', ['favorites' => 1]) }}"
            >
                <svg aria-hidden="true" viewBox="0 0 24 24">
                    <path d="m12 3 2.7 5.5 6.1.9-4.4 4.3 1 6.1-5.4-2.9-5.4 2.9 1-6.1-4.4-4.3 6.1-.9z"/>
                </svg>
                <span>Favorites</span>
            </a>
        </nav>

        <div class="request-indicator" aria-hidden="true"></div>
        <p class="sr-only" role="status" aria-live="polite" data-request-announcer></p>
    </body>
</html>
