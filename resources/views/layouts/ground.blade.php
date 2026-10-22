{{-- The phone layout for the ground pages and the customer's own page: one column, big targets, the brand, no sidebar. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? config('hijaz.name') }}</title>
    <link rel="icon" href="{{ \App\Support\PublicAsset::url('images/brand/favicon.svg') }}" type="image/svg+xml">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @include('pwa.head', request()->is('my', 'my/*') ? ['manifest' => '/my.webmanifest', 'title' => 'Hijaz'] : ['manifest' => '/portal.webmanifest', 'title' => 'Hijaz Portal'])
</head>
<body class="min-h-screen bg-brand-sky-light text-brand-ink antialiased" @isset($sw) data-service-worker="{{ $sw }}" @endisset>
    <header class="bg-white border-b border-brand-sky">
        <div class="mx-auto flex max-w-2xl items-center gap-3 px-4 py-3">
            <img src="{{ \App\Support\PublicAsset::url('images/brand/hijaz-mark.svg') }}" alt="" class="h-9 w-9">
            <div class="min-w-0 flex-1">
                <p class="truncate font-semibold text-brand-green-dark">{{ $heading ?? config('hijaz.name') }}</p>
                @isset($subheading)<p class="truncate text-sm text-brand-muted">{{ $subheading }}</p>@endisset
            </div>
            @isset($back)<a href="{{ $back }}" class="inline-flex min-h-11 items-center rounded-lg border border-brand-sky px-4 text-sm font-medium">Back</a>@endisset
        </div>
    </header>
    <main class="mx-auto max-w-2xl px-4 py-4 pb-24">
        @if (session('done'))<div class="mb-4 rounded-lg border border-brand-green bg-brand-green-light px-4 py-3 text-sm">{{ session('done') }}</div>@endif
        @if (session('problem'))<div class="mb-4 rounded-lg border border-brand-red bg-white px-4 py-3 text-sm text-brand-red-dark">{{ session('problem') }}</div>@endif
        @if ($errors->any())<div class="mb-4 rounded-lg border border-brand-red bg-white px-4 py-3 text-sm text-brand-red-dark">{{ $errors->first() }}</div>@endif
        {{ $slot ?? '' }}
        @yield('content')
    </main>
</body>
</html>
