@extends('layouts.ground', ['title' => 'Your booking', 'heading' => config('hijaz.name'), 'subheading' => 'Your booking'])
@section('content')
    <div class="rounded-xl border border-brand-sky bg-white px-4 py-5">
        <p>To see your booking, open the link the office sent you on WhatsApp or Viber. The link is personal: do not forward it.</p>
        <p class="mt-3 text-sm text-brand-muted">Need a new one? Call {{ \App\Support\Settings::get(\App\Support\Settings::COMPANY_PHONES) }}.</p>
    </div>
@endsection
