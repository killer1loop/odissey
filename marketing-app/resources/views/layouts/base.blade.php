<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="index, follow">
<meta name="theme-color" content="#05070c">
<meta name="color-scheme" content="dark">
<meta name="description" content="A fast, private media home for movies, series, music and live TV. Self-hosted for free, with hosted plans coming soon from $10/month.">
<link rel="canonical" href="{{ rtrim(config('app.url'), '/') }}{{ '/' }}">
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<link rel="preload" href="/odissey-orbit.webp" as="image" type="image/webp">

<meta property="og:type" content="website">
<meta property="og:site_name" content="Odissey">
<meta property="og:title" content="Odissey — Your media. One beautiful home.">
<meta property="og:description" content="A fast, private media home for movies, series, music and live TV. Self-hosted for free, with hosted plans coming soon from $10/month.">
<meta property="og:url" content="{{ rtrim(config('app.url'), '/') }}/">
<meta property="og:image" content="{{ rtrim(config('app.url'), '/') }}/social-card.png">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:alt" content="The Odissey orbit brand mark">

<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="Odissey — Your media. One beautiful home.">
<meta name="twitter:description" content="A fast, private media home for movies, series, music and live TV. Self-hosted for free, with hosted plans coming soon from $10/month.">
<meta name="twitter:image" content="{{ rtrim(config('app.url'), '/') }}/social-card.png">

<title>@yield('title', 'Odissey — Your media. One beautiful home.')</title>
@vite(['resources/css/app.css', 'resources/js/app.js'])
<script src="/vendor/htmx.min.js" defer></script>
</head>
<body>
@yield('content')
</body>
</html>
