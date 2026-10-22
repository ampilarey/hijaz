@extends('layouts.ground', ['title' => $booking->reference, 'heading' => $booking->trip->name, 'subheading' => $booking->reference.' · '.$booking->customer->name])
@section('content')
    <section class="mb-4 rounded-xl border border-brand-sky bg-white px-4 py-4">
        <p class="text-sm text-brand-muted">{{ $booking->trip->datesLabel('j F', 'j F Y') }} · {{ $booking->statusLabel() }}</p>
        @foreach ($booking->trip->hotels as $h)<p class="text-sm">{{ $h->cityLabel() }}: {{ $h->name }} ({{ $h->check_in_on->format('j M') }} – {{ $h->check_out_on->format('j M') }})</p>@endforeach
        <div class="mt-3 grid grid-cols-3 gap-2 text-sm">
            @foreach ($booking->currencies() as $c)
                <div><p class="text-brand-muted">Total {{ $c }}</p><p class="font-semibold">{{ $booking->total($c)->format() }}</p></div>
                <div><p class="text-brand-muted">Paid</p><p class="font-semibold">{{ $booking->paidIn($c)->format() }}</p></div>
                <div><p class="text-brand-muted">Balance</p><p class="font-semibold {{ $booking->balance($c)->minor > 0 ? 'text-brand-red-dark' : 'text-brand-green-dark' }}">{{ $booking->balance($c)->format() }}</p></div>
            @endforeach
        </div>
        @if ($booking->schedule->isNotEmpty())
            <ul class="mt-3 text-sm">
                @foreach ($booking->schedule as $s)<li>{{ $s->label }}: {{ $s->money()->format() }} by {{ $s->due_on->format('j M Y') }}</li>@endforeach
            </ul>
        @endif
        <a href="{{ route('my.invoice') }}" class="mt-3 inline-flex min-h-11 items-center rounded-lg border border-brand-sky px-4 text-sm font-medium">Invoice (PDF)</a>
    </section>

    @if (! $booking->isPaidUp() && $accounts->isNotEmpty())
        <section class="mb-4 rounded-xl border border-brand-green bg-brand-green-light px-4 py-4 text-sm">
            <p class="font-semibold">To pay, transfer to</p>
            @foreach ($accounts as $a)<p class="mt-1">{{ $a->bank ?: $a->name }} ({{ $a->currency }}): {{ $a->account_name }} · {{ $a->account_number }}</p>@endforeach
            <p class="mt-2 text-brand-muted">Write <strong>{{ $booking->reference }}</strong> in the remarks, then send the slip below.</p>
        </section>
    @endif

    <section class="mb-4 rounded-xl border border-brand-sky bg-white px-4 py-4">
        <h2 class="font-semibold">Send a bank slip</h2>
        <form method="post" action="{{ route('my.upload') }}" enctype="multipart/form-data" class="mt-2 grid gap-2">
            @csrf
            <input type="hidden" name="kind" value="slip">
            <input type="file" name="file" required accept="image/*,application/pdf" capture="environment" class="min-h-11 w-full min-w-0 max-w-full rounded-lg border border-brand-sky px-3 py-2 text-base">
            <div class="grid grid-cols-2 gap-2">
                <input name="amount" inputmode="decimal" placeholder="Amount (optional)" class="min-h-11 w-full min-w-0 rounded-lg border border-brand-sky px-3 text-base">
                <select name="currency" class="min-h-11 w-full min-w-0 rounded-lg border border-brand-sky px-3 text-base">@foreach (\App\Support\Currency::ALL as $c)<option value="{{ $c }}">{{ $c }}</option>@endforeach</select>
            </div>
            <button class="min-h-11 rounded-lg bg-brand-green-dark px-4 font-semibold text-white">Send the slip</button>
        </form>
        @if ($payments->isNotEmpty())
            <ul class="mt-3 divide-y divide-brand-sky text-sm">
                @foreach ($payments as $p)
                    <li class="flex min-h-11 items-center justify-between gap-3 py-1">
                        <span>{{ $p->money()->format() }} · {{ $p->paid_on?->format('j M Y') }} · {{ $p->statusLabel() }}</span>
                        @if ($p->status === \App\Models\Payment::SUCCEEDED)<a href="{{ route('my.receipt', $p) }}" class="inline-flex min-h-11 shrink-0 items-center px-2 underline">Receipt</a>@endif
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <section class="mb-4 rounded-xl border border-brand-sky bg-white px-4 py-4">
        <h2 class="font-semibold">Travellers and copies</h2>
        <ul class="mt-2 divide-y divide-brand-sky">
            @foreach ($booking->pilgrims as $bp)
                <li class="py-3">
                    <p class="font-medium">{{ $bp->pilgrim->name_en }}</p>
                    <p class="text-sm text-brand-muted">
                        @foreach ($docs[$bp->id] as $type => $state)
                            {{ \App\Models\Document::TYPES[$type] }}: <span class="{{ $state === 'verified' ? 'text-brand-green-dark' : ($state === 'missing' || $state === 'rejected' ? 'text-brand-red-dark' : '') }}">{{ $state }}</span>@if (! $loop->last) · @endif
                        @endforeach
                    </p>
                    @if (collect($docs[$bp->id])->contains(fn ($s) => $s !== 'verified'))
                        <form method="post" action="{{ route('my.upload') }}" enctype="multipart/form-data" class="mt-2 grid gap-2">
                            @csrf
                            <input type="hidden" name="kind" value="copy">
                            <input type="hidden" name="booking_pilgrim_id" value="{{ $bp->id }}">
                            <select name="type" class="min-h-11 w-full min-w-0 rounded-lg border border-brand-sky px-3 text-base">
                                @foreach ($docs[$bp->id] as $type => $state)@if ($state !== 'verified')<option value="{{ $type }}">{{ \App\Models\Document::TYPES[$type] }}</option>@endif @endforeach
                            </select>
                            <input type="file" name="file" required accept="image/*,application/pdf" capture="environment" class="min-h-11 w-full min-w-0 max-w-full rounded-lg border border-brand-sky px-3 py-2 text-base">
                            <button class="min-h-11 w-full rounded-lg bg-brand-green-dark px-4 font-semibold text-white">Send</button>
                        </form>
                    @endif
                </li>
            @endforeach
        </ul>
    </section>

    <form method="post" action="{{ route('my.leave') }}">@csrf<button class="inline-flex min-h-11 items-center px-2 text-sm text-brand-muted underline">Sign out of this page</button></form>
@endsection
