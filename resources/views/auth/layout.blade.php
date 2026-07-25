<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="color-scheme" content="dark">

        <title>@yield('title') · {{ config('app.name') }}</title>

        <style>
            :root {
                color-scheme: dark;
                font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
                background: #08090c;
                color: #f7f7f8;
            }

            * { box-sizing: border-box; }
            body { margin: 0; min-height: 100vh; background: radial-gradient(circle at 20% 0%, #22252d 0, #0d0f13 38rem, #08090c 70rem); }
            a { color: #e5a00d; }
            button, input { font: inherit; }
            .auth-shell { width: min(100% - 2rem, 34rem); margin: 0 auto; padding: 5rem 0 3rem; }
            .admin-shell { width: min(100% - 2rem, 72rem); margin: 0 auto; padding: 3rem 0; }
            .brand { display: inline-flex; gap: .75rem; align-items: center; margin-bottom: 2rem; color: #fff; text-decoration: none; font-size: 1.35rem; font-weight: 800; letter-spacing: .02em; }
            .brand-mark { width: 1.9rem; height: 1.9rem; display: grid; place-items: center; border-radius: .45rem; background: #e5a00d; color: #090a0c; }
            .card { border: 1px solid #2d3038; border-radius: 1rem; background: rgba(20, 22, 27, .94); padding: clamp(1.4rem, 4vw, 2.25rem); box-shadow: 0 1.5rem 5rem rgba(0, 0, 0, .32); }
            h1 { margin: 0 0 .65rem; font-size: clamp(1.65rem, 4vw, 2.25rem); }
            h2 { margin: 0; }
            p { color: #aeb1ba; line-height: 1.55; }
            .field { display: grid; gap: .45rem; margin-top: 1.1rem; }
            label { font-size: .9rem; font-weight: 700; color: #d9dbe0; }
            input { width: 100%; border: 1px solid #3a3e48; border-radius: .6rem; background: #0e1014; color: #fff; padding: .8rem .9rem; outline: none; }
            input:focus { border-color: #e5a00d; box-shadow: 0 0 0 3px rgba(229, 160, 13, .18); }
            .check { display: flex; align-items: center; gap: .6rem; margin-top: 1rem; }
            .check input { width: auto; }
            .button { display: inline-flex; justify-content: center; border: 0; border-radius: .6rem; background: #e5a00d; color: #08090c; padding: .8rem 1.1rem; font-weight: 800; cursor: pointer; text-decoration: none; }
            .button:hover { background: #f1b52f; }
            .button-secondary { background: #2d313a; color: #fff; }
            .button-danger { background: #8b2f38; color: #fff; }
            form > .button { width: 100%; margin-top: 1.5rem; }
            .errors { margin: 1rem 0; border: 1px solid #7d343b; border-radius: .6rem; background: #2a1518; color: #ffbbc1; padding: .8rem 1rem; }
            .errors ul { margin: 0; padding-left: 1.25rem; }
            .status { margin: 1rem 0; border: 1px solid #3e714b; border-radius: .6rem; background: #14261a; color: #b9efc5; padding: .8rem 1rem; }
            .topline { display: flex; justify-content: space-between; align-items: center; gap: 1rem; margin-bottom: 1.5rem; }
            .topline form { margin: 0; }
            .topline form > .button { width: auto; margin: 0; }
            .grid { display: grid; grid-template-columns: minmax(18rem, 26rem) 1fr; gap: 1.25rem; align-items: start; }
            .users { display: grid; gap: .75rem; }
            .user { display: flex; align-items: center; justify-content: space-between; gap: 1rem; border: 1px solid #30333c; border-radius: .75rem; padding: .9rem 1rem; }
            .user strong, .user span { display: block; }
            .user span { margin-top: .2rem; color: #9ca0aa; font-size: .88rem; }
            .badge { display: inline-block !important; margin-left: .35rem; border-radius: 999px; padding: .15rem .45rem; background: #3b3119; color: #ffd36f !important; font-size: .7rem !important; text-transform: uppercase; }
            .user form > .button { width: auto; margin: 0; padding: .55rem .75rem; }

            @media (max-width: 760px) {
                .auth-shell { padding-top: 2rem; }
                .grid { grid-template-columns: 1fr; }
                .topline { align-items: flex-start; flex-direction: column; }
            }
        </style>
    </head>
    <body>
        <main class="@yield('shell', 'auth-shell')">
            <a class="brand" href="{{ route('home') }}">
                <span class="brand-mark" aria-hidden="true">O</span>
                <span>Odissey</span>
            </a>

            @yield('content')
        </main>
    </body>
</html>
