@extends('layouts.ground', ['title' => $rollCall->moment, 'heading' => $rollCall->moment, 'subheading' => ($rollCall->bus?->name ?? 'Whole trip').' · '.$trip->code.' · '.$rollCall->taken_at->format('j M H:i'), 'back' => route('ground.trip', $trip), 'sw' => route('ground.index')])
@section('content')
<div data-ground-count="{{ $rollCall->id }}" data-sync-url="{{ route('ground.sync') }}">
    <div data-ground-status hidden class="mb-4 rounded-lg border-l-4 border-brand-red bg-white px-4 py-3" role="status" aria-live="polite">
        <p class="font-medium" data-ground-status-headline></p>
        <p class="text-sm text-brand-muted" data-ground-status-detail></p>
    </div>
    <p class="mb-3 text-sm text-brand-muted" data-ground-tally>{{ $marks->where('state', 'present')->count() }} here · {{ $marks->where('state', 'absent')->count() }} not here · {{ $marks->where('state', 'excused')->count() }} excused · {{ $travellers->count() - $marks->count() }} not counted</p>
    <ul class="divide-y divide-brand-sky rounded-xl border border-brand-sky bg-white">
        @foreach ($travellers as $bp)
            @php($state = $marks[$bp->id]->state ?? null)
            <li class="px-3 py-3" data-ground-traveller="{{ $bp->id }}" data-ground-state="{{ $state ?? '' }}">
                <div class="flex items-center justify-between gap-2">
                    <span><span class="font-medium">{{ $bp->pilgrim->name_en }}</span><span class="block text-sm text-brand-muted">{{ $bp->booking->customer->name }}{{ $bp->busGroup ? ' · '.$bp->busGroup->name : '' }}</span></span>
                    <span class="text-sm text-brand-muted" data-ground-mark-status>{{ $state ? $states[$state] : 'Not counted' }}</span>
                </div>
                @if ($mayMark)
                    <div class="mt-2 grid grid-cols-3 gap-2">
                        @foreach ($states as $k => $label)
                            <button type="button" data-ground-mark="{{ $k }}" class="min-h-11 rounded-lg border px-2 text-sm font-medium {{ $state === $k ? 'border-brand-green-dark bg-brand-green-light text-brand-green-dark' : 'border-brand-sky text-brand-muted' }}">{{ $label }}</button>
                        @endforeach
                    </div>
                @endif
            </li>
        @endforeach
    </ul>
</div>
@endsection
