<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    {{-- Indexing is opt in, and the default is the safe half.

         Almost everything here sits behind a guard, and the handful of pages
         that do not are either a transient answer about one document or a
         listing whose business has not consented to being findable. A default
         of "index" would mean any new public route is crawlable the moment
         somebody adds it, and forgetting to opt out is a great deal easier
         than forgetting to opt in. --}}
    <meta name="robots" content="{{ $robots ?? 'noindex, nofollow' }}">

    {{-- Installable field client. Dusk, so an installed app does not flash white
         at launch. --}}
    <link rel="manifest" href="{{ asset('build/manifest.webmanifest') }}">
    <meta name="theme-color" content="#0E1E2E">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <link rel="apple-touch-icon" href="{{ asset('icons/icon-192.png') }}">
    <title inertia>{{ config('app.name', 'GeoVerify') }}</title>
    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.tsx'])
    @inertiaHead
</head>
<body>
    @inertia
</body>
</html>
