<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('hijaz.name') }}</title>
    <meta name="description" content="The {{ config('hijaz.name') }} website is on its way.">
    <meta name="theme-color" content="#01a85a">
    <link rel="icon" href="{{ \App\Support\PublicAsset::url('favicon.ico') }}" sizes="32x32">
    <link rel="icon" href="{{ \App\Support\PublicAsset::url('images/brand/favicon.svg') }}" type="image/svg+xml">
    <link rel="apple-touch-icon" href="{{ \App\Support\PublicAsset::url('apple-touch-icon.png') }}">
    <meta property="og:title" content="{{ config('hijaz.name') }}">
    <meta property="og:description" content="Our new website is on its way.">
    <meta property="og:image" content="{{ \App\Support\PublicAsset::url('images/brand/hijaz-logo-on-blue.png') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-brand-sky-light text-brand-ink antialiased">
    <main class="flex min-h-screen flex-col items-center justify-center px-6 py-16 text-center">
        <img src="{{ \App\Support\PublicAsset::url('images/brand/hijaz-logo.svg') }}" alt="{{ config('hijaz.name') }}" width="742" height="668" class="w-72 sm:w-96">
        <h1 class="mt-10 font-display text-4xl font-bold tracking-tight text-brand-red sm:text-5xl">Our new website is on its way</h1>
        <p class="mt-5 max-w-md text-lg text-brand-muted">
            Hajj and Umrah journeys, coming soon.
        </p>
    </main>
</body>
</html>
