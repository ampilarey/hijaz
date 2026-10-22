@extends('layouts.ground', ['title' => 'Customer portal', 'heading' => config('hijaz.name'), 'subheading' => 'Customer portal'])
@section('content')
    <section class="rounded-xl border border-brand-sky bg-white px-4 py-5">
        <h1 class="text-lg font-semibold">Sign in</h1>
        <form method="post" action="{{ route('my.login') }}" class="mt-3 grid gap-3">
            @csrf
            <label class="grid gap-1 text-sm font-medium">Mobile number
                <input name="phone" type="tel" inputmode="tel" autocomplete="tel" required value="{{ old('phone') }}" class="min-h-11 w-full rounded-lg border border-brand-sky px-3 text-base font-normal">
            </label>
            <label class="grid gap-1 text-sm font-medium">Password
                <input name="password" type="password" autocomplete="current-password" required class="min-h-11 w-full rounded-lg border border-brand-sky px-3 text-base font-normal">
            </label>
            <button class="min-h-11 rounded-lg bg-brand-green-dark px-4 font-semibold text-white">Sign in</button>
        </form>
    </section>
    <section class="mt-4 rounded-xl border border-brand-sky bg-white px-4 py-4 text-sm">
        <p class="font-semibold">First time?</p>
        <p class="mt-1">Open the link the office sent you on WhatsApp, Viber or Telegram. Inside, set a password; after that you sign in here with your mobile number.</p>
        <p class="mt-2 text-brand-muted">No link, or forgot your password? Call {{ \App\Support\Settings::get(\App\Support\Settings::COMPANY_PHONES) }} for a new link.</p>
    </section>
@endsection
