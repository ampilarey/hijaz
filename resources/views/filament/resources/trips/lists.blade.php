<x-filament-panels::page>
    @php
        $link = fn (string $url, string $label, string $color = 'gray') => '<a href="'.e($url).'" target="_blank" rel="noopener" class="fi-btn fi-btn-size-md fi-btn-color-'.$color.'" style="display:inline-flex; align-items:center; gap:.375rem; min-height:44px; padding:.5rem .875rem; border-radius:.5rem; border:1px solid var(--gray-300,#d6d3d1); font-size:.875rem; font-weight:600;">'.e($label).'</a>';
    @endphp

    <x-filament::section>
        <x-slot name="heading">Trip list (manifest) · {{ $travellers }} travellers</x-slot>
        <x-slot name="description">Pick the shape the reader wants. PDF prints; CSV opens in a spreadsheet.</x-slot>
        <div style="display:grid; gap:.75rem;">
            @foreach ($presets as $preset => $label)
                <div style="display:flex; flex-wrap:wrap; gap:.5rem; align-items:center;">
                    <span style="min-width:14rem;">{{ $label }}</span>
                    {!! $link(route('lists.manifest', ['trip' => $trip, 'preset' => $preset]), 'PDF', 'primary') !!}
                    {!! $link(route('lists.manifest', ['trip' => $trip, 'preset' => $preset, 'format' => 'csv']), 'CSV') !!}
                </div>
            @endforeach
            <div style="display:flex; flex-wrap:wrap; gap:.5rem; align-items:center;">
                <span style="min-width:14rem;">Payment status</span>
                {!! $link(route('lists.payments', $trip), 'PDF', 'primary') !!}
                {!! $link(route('lists.payments', ['trip' => $trip, 'format' => 'csv']), 'CSV') !!}
            </div>
            <div style="display:flex; flex-wrap:wrap; gap:.5rem; align-items:center;">
                <span style="min-width:14rem;">Documents still owed</span>
                {!! $link(route('lists.documents', $trip), 'PDF', 'primary') !!}
                {!! $link(route('lists.documents', ['trip' => $trip, 'format' => 'csv']), 'CSV') !!}
            </div>
        </div>
    </x-filament::section>

    @if ($groups->isNotEmpty())
        <x-filament::section>
            <x-slot name="heading">Group lists</x-slot>
            <x-slot name="description">Each group flies on its own flights; the airline gets one list per flight.</x-slot>
            @foreach ($groups as $group)
                <div style="display:flex; flex-wrap:wrap; gap:.5rem; align-items:center; margin-bottom:.5rem;">
                    <span style="min-width:14rem;">{{ $group->name }} · {{ $group->members_count }} people · {{ $group->flightsLabel() }}</span>
                    {!! $link(route('lists.manifest', ['trip' => $trip, 'preset' => 'airline', 'group' => $group->id]), 'Airline list PDF', 'primary') !!}
                    {!! $link(route('lists.manifest', ['trip' => $trip, 'preset' => 'office', 'group' => $group->id]), 'Office list PDF') !!}
                </div>
            @endforeach
        </x-filament::section>
    @endif

    <x-filament::section>
        <x-slot name="heading">Bus lists</x-slot>
        @forelse ($buses as $bus)
            <div style="display:flex; flex-wrap:wrap; gap:.5rem; align-items:center; margin-bottom:.5rem;">
                <span style="display:inline-block;width:.75rem;height:.75rem;border-radius:9999px;background:{{ $bus->colour ?: '#a8a29e' }}"></span>
                <span style="min-width:14rem;">{{ $bus->group ? $bus->group->name.' · ' : '' }}{{ $bus->name }} · {{ $bus->members_count }} on board{{ $bus->leaderLabel() ? ' · '.$bus->leaderLabel() : '' }}</span>
                {!! $link(route('lists.bus', $bus), 'Bus list PDF', 'primary') !!}
                @if ($canPrintCards && $bus->members_count > 0)
                    {!! $link(route('lists.cards', ['kind' => 'luggage', 'bus' => $bus->id]), 'Luggage tags') !!}
                @endif
            </div>
        @empty
            <p class="fi-color-gray">No buses yet. Add them on the trip page under Buses.</p>
        @endforelse
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Room lists</x-slot>
        <x-slot name="description">The hotel copy leaves out phone numbers and families.</x-slot>
        @forelse ($hotels as $hotel)
            <div style="display:flex; flex-wrap:wrap; gap:.5rem; align-items:center; margin-bottom:.5rem;">
                <span style="min-width:14rem;">{{ $hotel->cityLabel() }} · {{ $hotel->name }} · {{ $hotel->rooms_count }} rooms</span>
                {!! $link(route('lists.rooms', $hotel), 'Office copy', 'primary') !!}
                {!! $link(route('lists.rooms', ['tripHotel' => $hotel, 'hotel' => 1]), 'Hotel copy') !!}
                {!! $link(route('lists.rooms', ['tripHotel' => $hotel, 'format' => 'csv']), 'CSV') !!}
            </div>
        @empty
            <p class="fi-color-gray">No hotels on this trip yet.</p>
        @endforelse
    </x-filament::section>

    @if ($excursions->isNotEmpty())
        <x-filament::section>
            <x-slot name="heading">Additional trips</x-slot>
            @foreach ($excursions as $x)
                <div style="display:flex; flex-wrap:wrap; gap:.5rem; align-items:center; margin-bottom:.5rem;">
                    <span style="min-width:14rem;">{{ $x->title }}</span>
                    {!! $link(route('lists.excursion', $x), 'Participants PDF', 'primary') !!}
                    {!! $link(route('lists.excursion', ['tripExcursion' => $x, 'format' => 'csv']), 'CSV') !!}
                </div>
            @endforeach
        </x-filament::section>
    @endif

    @if ($canPrintCards)
        <x-filament::section>
            <x-slot name="heading">Cards and tags for the whole trip</x-slot>
            <x-slot name="description">Each traveller gets one card and one tag; printing again reuses the same QR. Select rows under Travellers to print a few.</x-slot>
            <div style="display:flex; flex-wrap:wrap; gap:.5rem;">
                {!! $link(route('lists.cards', ['kind' => 'customer', 'trip' => $trip->id]), 'Customer cards', 'primary') !!}
                {!! $link(route('lists.cards', ['kind' => 'luggage', 'trip' => $trip->id]), 'Luggage tags', 'primary') !!}
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
