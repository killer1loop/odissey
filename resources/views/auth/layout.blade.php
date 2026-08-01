<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="color-scheme" content="dark">
        <meta name="theme-color" content="#05070c">

        <title>@yield('title') · {{ config('app.name') }}</title>
        <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="auth-body">
        <main class="auth-shell" data-page-body-class="auth-body" hx-boost="false">
            <a class="auth-brand" href="{{ route('home') }}" aria-label="Odissey">
                <span class="brand-mark" aria-hidden="true"><span></span></span>
                <span>Odissey</span>
            </a>

            @yield('content')
        </main>
    </body>
</html>
