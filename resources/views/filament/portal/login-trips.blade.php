{{--
    The front door, under the sign-in form: the trips ahead, with what a
    poster outside the office would say and nothing more. Staff sign in
    for the rest; nobody else needs an account to read this.
--}}
<div style="margin-top: 1.5rem; display: grid; gap: .75rem;">
    <p style="font-weight: 600;">{{ $trips->isEmpty() ? 'No trips announced yet' : 'Upcoming trips' }}</p>
    @foreach ($trips as $trip)
        <div style="border: 1px solid var(--gray-200, #e7e5e4); border-radius: .75rem; padding: .75rem 1rem;">
            <p style="font-weight: 600;">{{ $trip->name }} <span class="fi-color-gray" style="font-weight: 400;">· {{ $trip->code }}</span></p>
            <p style="font-size: .875rem;">{{ $trip->departs_on->format('j M') }} – {{ $trip->returns_on->format('j M Y') }} · {{ $trip->nights() }} nights</p>
            @if ($trip->hotels->isNotEmpty())
                <p class="fi-color-gray" style="font-size: .875rem;">{{ $trip->hotels->map(fn ($h) => $h->cityLabel().': '.$h->name)->implode(' · ') }}</p>
            @endif
            <p style="font-size: .875rem; margin-top: .25rem;">
                @php($from = $trip->priceTiers->where('pax_type', 'adult')->sortBy('amount_minor')->first())
                @if ($from)<span>From {{ $from->money()->format() }}</span> · @endif
                <span style="color: {{ $trip->seatsAvailable() > 0 ? 'var(--success-600, #15803d)' : 'var(--danger-600, #b91c1c)' }};">{{ $trip->seatsAvailable() > 0 ? $trip->seatsAvailable().' seats left' : 'Full' }}</span>
                · <span class="fi-color-gray">{{ $trip->status === \App\Models\Trip::OPEN ? 'Taking registrations' : strtolower($trip->statusLabel()) }}</span>
            </p>
        </div>
    @endforeach
    <p class="fi-color-gray" style="font-size: .75rem;">{{ \App\Support\Settings::get(\App\Support\Settings::COMPANY_PHONES) }} · {{ \App\Support\Settings::get(\App\Support\Settings::COMPANY_EMAIL) }}</p>
</div>
