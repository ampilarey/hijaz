@extends('layouts.ground', ['title' => 'Agreement', 'heading' => 'Agreement', 'subheading' => $booking->reference.' · '.$booking->trip->name, 'back' => route('my.home')])
@section('content')
    <section class="mb-4 rounded-xl border border-brand-sky bg-white px-4 py-4">
        <p class="text-sm text-brand-muted">For {{ $booking->pilgrims->map(fn ($bp) => $bp->pilgrim->name_en)->implode(', ') }}</p>
        <div class="mt-3 whitespace-pre-line text-[15px] leading-relaxed">{{ $agreement->terms }}</div>
    </section>

    @if ($agreement->isSigned())
        <section class="rounded-xl border border-brand-green bg-brand-green-light px-4 py-4">
            <p class="font-semibold">{{ $agreement->statusLabel() }}</p>
            <a href="{{ route('my.agreement.pdf') }}" class="mt-3 inline-flex min-h-11 items-center rounded-lg border border-brand-sky bg-white px-4 text-sm font-medium">Download the signed copy (PDF)</a>
        </section>
    @else
        <section class="mb-4 rounded-xl border border-brand-sky bg-white px-4 py-4">
            <h2 class="font-semibold">Sign on this screen</h2>
            <form method="post" action="{{ route('my.agreement.sign') }}" class="mt-3 grid gap-3" data-signature-form>
                @csrf
                <label class="grid gap-1 text-sm font-medium">Your full name
                    <input name="name" required maxlength="160" value="{{ old('name', $booking->contactName()) }}" class="min-h-11 w-full rounded-lg border border-brand-sky px-3 text-base font-normal">
                </label>
                <div class="grid gap-1">
                    <span class="text-sm font-medium">Your signature</span>
                    <canvas data-signature-pad class="h-44 w-full touch-none rounded-lg border border-brand-sky bg-white"></canvas>
                    <button type="button" data-signature-clear class="inline-flex min-h-11 w-fit items-center px-2 text-sm text-brand-muted underline">Clear and draw again</button>
                </div>
                <input type="hidden" name="signature" data-signature-value>
                <label class="flex min-h-11 items-start gap-3 text-sm">
                    <input type="checkbox" name="agree" value="1" required class="mt-0.5 h-6 w-6 shrink-0">
                    <span>I have read this agreement and accept it for everyone on the booking.</span>
                </label>
                <button class="min-h-11 rounded-lg bg-brand-green-dark px-4 font-semibold text-white">Sign the agreement</button>
            </form>
        </section>

        <section class="rounded-xl border border-brand-sky bg-white px-4 py-4">
            <h2 class="font-semibold">Or send a signed paper copy</h2>
            <p class="mt-1 text-sm text-brand-muted">Download it, print, sign, then photograph or scan it.</p>
            <a href="{{ route('my.agreement.pdf') }}" class="mt-3 inline-flex min-h-11 items-center rounded-lg border border-brand-sky px-4 text-sm font-medium">Download to print (PDF)</a>
            <form method="post" action="{{ route('my.upload') }}" enctype="multipart/form-data" class="mt-3 grid gap-2">
                @csrf
                <input type="hidden" name="kind" value="agreement">
                <input type="file" name="file" required accept="image/*,application/pdf" capture="environment" class="min-h-11 w-full min-w-0 max-w-full rounded-lg border border-brand-sky px-3 py-2 text-base">
                <button class="min-h-11 w-full rounded-lg bg-brand-green-dark px-4 font-semibold text-white">Send the signed copy</button>
            </form>
        </section>
    @endif
@endsection
