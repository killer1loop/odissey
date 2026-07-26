<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="color-scheme" content="dark">

        <title>@yield('title') · {{ config('app.name') }}</title>

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="auth-body">
        <main class="auth-shell">
            <a class="auth-brand" href="{{ route('home') }}" aria-label="Odissey">
                <span class="brand-mark" aria-hidden="true"><span></span></span>
                <span>Odissey</span>
            </a>

            @yield('content')
        </main>
    </body>
</html>
