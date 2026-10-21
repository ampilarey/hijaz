<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }}</title>
    <meta name="description" content="Hijaz, a new journey from Rihla Travels, is on its way.">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-stone-50 text-stone-900 antialiased">
    <main class="flex min-h-screen flex-col items-center justify-center px-6 text-center">
        <p class="text-sm font-medium uppercase tracking-[0.3em] text-amber-700">Rihla Travels</p>
        <h1 class="mt-4 text-6xl font-semibold tracking-tight sm:text-8xl">Hijaz</h1>
        <p class="mt-6 max-w-md text-lg text-stone-600">
            A new journey is on its way. This page will become the Hijaz website.
        </p>
        <a href="https://rihla.mv" class="mt-10 rounded-full border border-stone-300 px-6 py-3 text-sm font-medium transition hover:border-stone-900">
            Visit rihla.mv
        </a>
    </main>
</body>
</html>
