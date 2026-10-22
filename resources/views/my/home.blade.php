@extends('layouts.ground', ['title' => $booking->reference, 'heading' => $booking->trip->name, 'subheading' => $booking->reference.' · '.$booking->customer->name])
@section('content')
    @if ($bookings->count() > 1)
        <form method="post" action="{{ route('my.choose') }}" class="mb-4 grid gap-2 rounded-xl border border-brand-sky bg-white px-4 py-3">
            @csrf
            <label class="grid gap-1 text-sm font-medium">Your bookings
                <select name="booking_id" onchange="this.form.submit()" class="min-h-11 w-full rounded-lg border border-brand-sky px-3 text-base font-normal">
                    @foreach ($bookings as $b)<option value="{{ $b->id }}" @selected($b->id === $booking->id)>{{ $b->reference }} · {{ $b->trip->name }}</option>@endforeach
                </select>
            </label>
            <noscript><button class="min-h-11 rounded-lg border border-brand-sky px-4 text-sm font-medium">Show</button></noscript>
        </form>
    @endif

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

    <section class="mb-4 rounded-xl border {{ $agreement->isSigned() ? 'border-brand-sky' : 'border-brand-green' }} bg-white px-4 py-4">
        <h2 class="font-semibold">Agreement</h2>
        <p class="mt-1 text-sm {{ $agreement->isSigned() ? 'text-brand-green-dark' : 'text-brand-muted' }}">{{ $agreement->isSigned() ? $agreement->statusLabel().'.' : 'Please read and sign the agreement for this booking: on this screen, or send a signed paper copy.' }}</p>
        <div class="mt-3 flex flex-wrap gap-2">
            @unless ($agreement->isSigned())<a href="{{ route('my.agreement') }}" class="inline-flex min-h-11 items-center rounded-lg bg-brand-green-dark px-4 text-sm font-semibold text-white">Read and sign</a>@endunless
            <a href="{{ route('my.agreement.pdf') }}" class="inline-flex min-h-11 items-center rounded-lg border border-brand-sky px-4 text-sm font-medium">{{ $agreement->isSigned() ? 'Download signed copy' : 'Download (PDF)' }}</a>
        </div>
    </section>

    @php($ready = $booking->pilgrims->filter(fn ($bp) => \App\Services\Invoices\CustomerPapers::letterReady($bp)))
    <section class="mb-4 rounded-xl border border-brand-sky bg-white px-4 py-4">
        <h2 class="font-semibold">Travel letters</h2>
        @if ($ready->isEmpty())
            <p class="mt-1 text-sm text-brand-muted">Each traveller's travel acceptance letter appears here once the booking is confirmed and their flight is set.</p>
        @else
            <ul class="mt-2 divide-y divide-brand-sky text-sm">
                @foreach ($ready as $bp)
                    <li class="flex min-h-11 items-center justify-between gap-3 py-1">
                        <span><span class="font-medium">{{ $bp->pilgrim->name_en }}</span><span class="block text-brand-muted">{{ $bp->flightsLabel() }}</span></span>
                        <a href="{{ route('my.letters', $bp) }}" class="inline-flex min-h-11 shrink-0 items-center rounded-lg border border-brand-sky px-4 font-medium">Letter</a>
                    </li>
                @endforeach
            </ul>
            @if ($ready->count() > 1)<a href="{{ route('my.letters') }}" class="mt-2 inline-flex min-h-11 items-center rounded-lg border border-brand-sky px-4 text-sm font-medium">All letters in one PDF</a>@endif
        @endif
    </section>

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

    @if ($customer)
        <section class="mb-4 rounded-xl border border-brand-sky bg-white px-4 py-4">
            <h2 class="font-semibold">{{ $customer->portal_password_set_at ? 'Change your password' : 'Set a password' }}</h2>
            <p class="mt-1 text-sm text-brand-muted">{{ $customer->portal_password_set_at ? 'You sign in with '.$customer->phone.' and your password.' : 'Then you can sign in any time at '.str(route('my.locked'))->after('://').' with '.($customer->phone ?: 'your mobile number').', without a new link.' }}</p>
            <form method="post" action="{{ route('my.password') }}" class="mt-3 grid gap-2">
                @csrf
                <input name="password" type="password" autocomplete="new-password" minlength="8" required placeholder="New password, at least 8 characters" class="min-h-11 w-full rounded-lg border border-brand-sky px-3 text-base">
                <input name="password_confirmation" type="password" autocomplete="new-password" minlength="8" required placeholder="The same again" class="min-h-11 w-full rounded-lg border border-brand-sky px-3 text-base">
                <button class="min-h-11 rounded-lg border border-brand-sky px-4 font-medium">Save password</button>
            </form>
        </section>
    @endif

    <form method="post" action="{{ route('my.leave') }}">@csrf<button class="inline-flex min-h-11 items-center px-2 text-sm text-brand-muted underline">Sign out</button></form>
@endsection
