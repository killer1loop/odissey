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
                    <a class="nav-item is-active" href="{{ route('home') }}" aria-current="page">
                        <svg aria-hidden="true" viewBox="0 0 24 24">
                            <path d="M3 10.5 12 3l9 7.5v9a1.5 1.5 0 0 1-1.5 1.5h-15A1.5 1.5 0 0 1 3 19.5z"/>
                            <path d="M9 21v-6h6v6"/>
                        </svg>
                        <span>Home</span>
                    </a>
                    <span class="nav-item is-planned" aria-disabled="true">
                        <svg aria-hidden="true" viewBox="0 0 24 24">
                            <rect x="3" y="5" width="18" height="14" rx="2"/>
                            <path d="m9 10 5 3-5 3z"/>
                        </svg>
                        <span>Video</span>
                    </span>
                    <span class="nav-item is-planned" aria-disabled="true">
                        <svg aria-hidden="true" viewBox="0 0 24 24">
                            <path d="M9 18V5l10-2v13"/>
                            <circle cx="6" cy="18" r="3"/>
                            <circle cx="16" cy="16" r="3"/>
                        </svg>
                        <span>Music</span>
                    </span>
                    <span class="nav-item is-planned" aria-disabled="true">
                        <svg aria-hidden="true" viewBox="0 0 24 24">
                            <rect x="3" y="6" width="18" height="13" rx="2"/>
                            <path d="M8 3h8M12 3v3M7 11h4M7 15h7"/>
                        </svg>
                        <span>Live TV</span>
                    </span>

                    <p class="nav-label">Your media</p>
                    <span class="nav-item is-planned" aria-disabled="true">
                        <svg aria-hidden="true" viewBox="0 0 24 24">
                            <path d="m12 3 2.7 5.5 6.1.9-4.4 4.3 1 6.1-5.4-2.9-5.4 2.9 1-6.1-4.4-4.3 6.1-.9z"/>
                        </svg>
                        <span>Favorites</span>
                    </span>
                    <span class="nav-item is-planned" aria-disabled="true">
                        <svg aria-hidden="true" viewBox="0 0 24 24">
                            <circle cx="12" cy="12" r="9"/>
                            <path d="M12 7v5l3.5 2"/>
                        </svg>
                        <span>History</span>
                    </span>
                </nav>

                <div class="sidebar-footer">
                    <div class="avatar" aria-hidden="true">A</div>
                    <div>
                        <strong>First launch</strong>
                        <span>Admin setup planned</span>
                    </div>
                </div>
            </aside>

            <div class="workspace">
                <header class="topbar">
                    <div class="mobile-brand">
                        <span class="brand-mark" aria-hidden="true"><span></span></span>
                        <span>Odissey</span>
                    </div>

                    <label class="search-shell">
                        <svg aria-hidden="true" viewBox="0 0 24 24">
                            <circle cx="11" cy="11" r="6.5"/>
                            <path d="m16 16 4 4"/>
                        </svg>
                        <span class="sr-only">Search</span>
                        <input type="search" placeholder="Search is coming in the IPTV milestone" disabled>
                    </label>

                    <div class="topbar-actions">
                        <a class="icon-button" href="#roadmap" aria-label="View implementation roadmap">
                            <svg aria-hidden="true" viewBox="0 0 24 24">
                                <circle cx="12" cy="12" r="9"/>
                                <path d="M12 16v-4M12 8h.01"/>
                            </svg>
                        </a>
                        <span class="foundation-pill">Foundation</span>
                    </div>
                </header>

                <main id="main-content" tabindex="-1">
                    @yield('content')
                </main>
            </div>
        </div>

        <nav class="mobile-nav" aria-label="Mobile navigation">
            <a class="is-active" href="{{ route('home') }}" aria-current="page">
                <svg aria-hidden="true" viewBox="0 0 24 24">
                    <path d="M3 10.5 12 3l9 7.5v9a1.5 1.5 0 0 1-1.5 1.5h-15A1.5 1.5 0 0 1 3 19.5z"/>
                    <path d="M9 21v-6h6v6"/>
                </svg>
                <span>Home</span>
            </a>
            <span aria-disabled="true">
                <svg aria-hidden="true" viewBox="0 0 24 24">
                    <rect x="3" y="6" width="18" height="13" rx="2"/>
                    <path d="M8 3h8M12 3v3"/>
                </svg>
                <span>Live TV</span>
            </span>
            <span aria-disabled="true">
                <svg aria-hidden="true" viewBox="0 0 24 24">
                    <path d="m12 3 2.7 5.5 6.1.9-4.4 4.3 1 6.1-5.4-2.9-5.4 2.9 1-6.1-4.4-4.3 6.1-.9z"/>
                </svg>
                <span>Favorites</span>
            </span>
            <span aria-disabled="true">
                <svg aria-hidden="true" viewBox="0 0 24 24">
                    <circle cx="12" cy="12" r="9"/>
                    <path d="M12 7v5l3.5 2"/>
                </svg>
                <span>History</span>
            </span>
        </nav>

        <div class="request-indicator" aria-hidden="true"></div>
        <p class="sr-only" role="status" aria-live="polite" data-request-announcer></p>
    </body>
</html>
