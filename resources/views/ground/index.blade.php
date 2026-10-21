@extends('layouts.ground', ['title' => 'On the ground', 'heading' => 'On the ground', 'subheading' => 'Your trips', 'sw' => route('ground.index')])
@section('content')
    @forelse ($trips as $trip)
        <a href="{{ route('ground.trip', $trip) }}" class="mb-3 block rounded-xl border border-brand-sky bg-white px-4 py-4">
            <p class="font-semibold">{{ $trip->name }}</p>
            <p class="text-sm text-brand-muted">{{ $trip->code }} · {{ $trip->departs_on->format('j M') }} – {{ $trip->returns_on->format('j M Y') }} · {{ strtolower($trip->statusLabel()) }}</p>
        </a>
    @empty
        <p class="rounded-xl border border-brand-sky bg-white px-4 py-4 text-brand-muted">No trip on the road is yours. The office puts you on a bus as its leader and it appears here.</p>
    @endforelse
    <p class="mt-6 text-sm text-brand-muted">Open each trip once with a signal; after that the page works without one and sends what you tap when the signal returns.</p>
    <p class="mt-2 text-sm"><a href="{{ url('/portal') }}" class="underline">Back to the portal</a></p>
@endsection
