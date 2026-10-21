@extends('layouts.ground', ['title' => $trip->code, 'heading' => $trip->name, 'subheading' => $trip->code.' · '.$travellers->count().' people'.($ownBuses !== [] ? ' on your bus' : ''), 'back' => route('ground.index'), 'sw' => route('ground.index')])
@section('content')
<div data-ground-trip="{{ $trip->id }}" data-snapshot-url="{{ route('ground.snapshot', $trip) }}" data-sync-url="{{ route('ground.sync') }}">
    <div data-ground-status hidden class="mb-4 rounded-lg border-l-4 border-brand-red bg-white px-4 py-3" role="status" aria-live="polite">
        <p class="font-medium" data-ground-status-headline></p>
        <p class="text-sm text-brand-muted" data-ground-status-detail></p>
    </div>

    @if ($incidents->isNotEmpty())
        <section class="mb-4 rounded-xl border-l-4 border-brand-red bg-white px-4 py-3">
            <h2 class="font-semibold">{{ $incidents->count() }} open incident{{ $incidents->count() === 1 ? '' : 's' }}</h2>
            <ul class="mt-2 space-y-2 text-sm">
                @foreach ($incidents as $i)
                    <li><span class="font-medium">{{ $i->summary }}</span> <span class="text-brand-muted">· {{ $i->severityLabel() }} · {{ $i->categoryLabel() }}{{ $i->bookingPilgrim ? ' · '.$i->bookingPilgrim->pilgrim->name_en : '' }} · {{ $i->happened_at->format('j M H:i') }}</span></li>
                @endforeach
            </ul>
        </section>
    @endif

    <section class="mb-4 rounded-xl border border-brand-sky bg-white px-4 py-3">
        <h2 class="font-semibold">Head counts</h2>
        @if ($mayCount)
            <form method="post" action="{{ route('ground.counts.start', $trip) }}" class="mt-2 flex flex-wrap gap-2">
                @csrf
                <input name="moment" required maxlength="80" placeholder="Boarding at the hotel" class="min-h-11 flex-1 rounded-lg border border-brand-sky px-3 text-base">
                @if ($buses->isNotEmpty())
                    <select name="bus_group_id" class="min-h-11 rounded-lg border border-brand-sky px-3 text-base">
                        <option value="">Whole trip</option>
                        @foreach ($buses as $bus)<option value="{{ $bus->id }}" @selected(in_array($bus->id, $ownBuses, true))>{{ $bus->name }}</option>@endforeach
                    </select>
                @endif
                <button class="min-h-11 rounded-lg bg-brand-green-dark px-4 font-semibold text-white">Start a count</button>
            </form>
        @endif
        <ul class="mt-3 divide-y divide-brand-sky">
            @forelse ($rollCalls as $r)
                @php($missing = $r->unaccountedFor()->count())
                <li>
                    <a href="{{ route('ground.count', [$trip, $r]) }}" class="flex min-h-12 items-center justify-between gap-3 py-2">
                        <span><span class="font-medium">{{ $r->moment }}</span><span class="block text-sm text-brand-muted">{{ $r->bus?->name ?? 'Whole trip' }} · {{ $r->taken_at->format('j M H:i') }}</span></span>
                        <span class="whitespace-nowrap rounded-full px-3 py-1 text-sm font-medium {{ $missing === 0 ? 'bg-brand-green-light text-brand-green-dark' : 'bg-brand-red text-white' }}">{{ $missing === 0 ? 'All here' : $missing.' missing' }}</span>
                    </a>
                </li>
            @empty
                <li class="py-2 text-sm text-brand-muted">None yet. Start one wherever somebody could be left behind.</li>
            @endforelse
        </ul>
    </section>

    @if ($mayRaise)
        <section class="mb-4 rounded-xl border border-brand-sky bg-white px-4 py-3">
            <h2 class="font-semibold">Raise an incident</h2>
            <form method="post" action="{{ route('ground.incidents.raise', $trip) }}" class="mt-2 grid gap-2" data-ground-incident>
                @csrf
                <input type="hidden" name="client_uuid" value="{{ \Illuminate\Support\Str::uuid() }}">
                <div class="grid grid-cols-2 gap-2">
                    <select name="severity" required class="min-h-11 rounded-lg border border-brand-sky px-3 text-base">
                        @foreach (\App\Models\Incident::SEVERITIES as $k => $v)<option value="{{ $k }}" @selected($k === 'minor')>{{ $v }}</option>@endforeach
                    </select>
                    <select name="category" required class="min-h-11 rounded-lg border border-brand-sky px-3 text-base">
                        @foreach (\App\Models\Incident::CATEGORIES as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach
                    </select>
                </div>
                <select name="booking_pilgrim_id" class="min-h-11 rounded-lg border border-brand-sky px-3 text-base">
                    <option value="">Nobody in particular</option>
                    @foreach ($travellers as $bp)<option value="{{ $bp->id }}">{{ $bp->pilgrim->name_en }}</option>@endforeach
                </select>
                <input name="summary" required maxlength="255" placeholder="What happened, in one line" class="min-h-11 rounded-lg border border-brand-sky px-3 text-base">
                <input name="location" maxlength="255" placeholder="Where" class="min-h-11 rounded-lg border border-brand-sky px-3 text-base">
                <textarea name="detail" rows="2" placeholder="Anything else" class="rounded-lg border border-brand-sky px-3 py-2 text-base"></textarea>
                <button class="min-h-11 rounded-lg bg-brand-red-dark px-4 font-semibold text-white">Raise it</button>
            </form>
        </section>
    @endif

    @if ($mayCollect && $excursions->isNotEmpty())
        <section class="mb-4 rounded-xl border border-brand-sky bg-white px-4 py-3">
            <h2 class="font-semibold">Riyal to collect for additional trips</h2>
            <ul class="mt-2 divide-y divide-brand-sky">
                @foreach ($excursions as $xb)
                    <li class="flex min-h-12 items-center justify-between gap-3 py-2" data-ground-cash="{{ $xb->id }}">
                        <span><span class="font-medium">{{ $xb->customer->name }}</span><span class="block text-sm text-brand-muted">{{ $xb->excursion->title }} · {{ $xb->participants->map(fn ($p) => $p->bookingPilgrim?->pilgrim?->name_en)->filter()->implode(', ') }}</span></span>
                        <button type="button" data-ground-collect data-amount="{{ $xb->balance()->minor }}" class="min-h-11 whitespace-nowrap rounded-lg bg-brand-green-dark px-3 font-semibold text-white">Paid {{ $xb->balance()->format() }}</button>
                    </li>
                @endforeach
            </ul>
            <p class="mt-2 text-xs text-brand-muted">Each tap records cash into your float, verified. Hand it to Finance after the trip.</p>
        </section>
    @endif

    <section class="rounded-xl border border-brand-sky bg-white px-4 py-3">
        <h2 class="font-semibold">People</h2>
        <ul class="mt-2 divide-y divide-brand-sky text-sm">
            @foreach ($travellers as $bp)
                <li class="flex items-center justify-between gap-2 py-2">
                    <span><span class="font-medium">{{ $bp->pilgrim->name_en }}</span><span class="block text-brand-muted">{{ $bp->booking->customer->name }}{{ $bp->busGroup ? ' · '.$bp->busGroup->name : '' }}{{ $bp->roomAssignments->isNotEmpty() ? ' · '.$bp->roomAssignments->map(fn ($a) => ($a->room?->hotel?->cityLabel() ?? '').' '.($a->room?->label ?? ''))->implode(', ') : '' }}</span></span>
                    @if ($bp->pilgrim->phone)<a href="tel:{{ $bp->pilgrim->phone }}" class="inline-flex min-h-11 shrink-0 items-center rounded-lg border border-brand-sky px-4">Call</a>@endif
                </li>
            @endforeach
        </ul>
    </section>
</div>
@endsection
