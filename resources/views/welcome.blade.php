<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }} Hajj &amp; Umrah</title>
    <meta name="description" content="Hijaz Hajj & Umrah, a new journey from Rihla Travels, is on its way.">
    <meta name="theme-color" content="#17a24a">
    <link rel="icon" href="/favicon.ico" sizes="32x32">
    <link rel="icon" href="/images/brand/favicon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <meta property="og:title" content="Hijaz Hajj &amp; Umrah">
    <meta property="og:description" content="A new journey from Rihla Travels is on its way.">
    <meta property="og:image" content="{{ url('/images/brand/hijaz-logo.png') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-brand-sand text-brand-ink antialiased">
    <main class="flex min-h-screen flex-col items-center justify-center px-6 py-16 text-center">
        <img src="/images/brand/hijaz-logo.svg" alt="Hijaz Hajj &amp; Umrah" width="600" height="560" class="w-64 sm:w-80">
        <p class="mt-10 text-sm font-medium uppercase tracking-[0.3em] text-brand-green-dark">Rihla Travels</p>
        <h1 class="mt-3 font-display text-4xl font-bold tracking-tight text-brand-red sm:text-5xl">A new journey is on its way</h1>
        <p class="mt-5 max-w-md text-lg text-brand-stone">
            This page will become the Hijaz Hajj &amp; Umrah website.
        </p>
        <a href="https://rihla.mv" class="mt-10 rounded-full bg-brand-green px-6 py-3 text-sm font-medium text-white transition hover:bg-brand-green-dark">
            Visit rihla.mv
        </a>
    </main>
</body>
</html>
