<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">{{ $confirmedCount }} confirmed traveller{{ $confirmedCount === 1 ? '' : 's' }}{{ $draftCount > 0 ? ', '.$draftCount.' on draft bookings' : '' }} · {{ $trip->seatsTaken() }} of {{ $trip->capacity_total }} seats</x-slot>
        @if ($concerns === [])
            <p style="color:var(--success-600,#15803d); font-weight:600;">Nothing stands in the way. Buses, rooms, copies, visas and money are all settled.</p>
        @else
            <p class="fi-color-gray">{{ count($blocking) }} blocking · {{ count($attention) }} worth attention. Computed now from the records, never stored.</p>
        @endif
    </x-filament::section>

    @if ($blocking !== [])
        <x-filament::section>
            <x-slot name="heading"><span style="color:var(--danger-600,#b91c1c);">Blocks departure</span></x-slot>
            <ul style="margin:0; padding-left:1rem; display:grid; gap:.75rem;">
                @foreach ($blocking as $c)
                    <li>
                        <strong>{{ $c['headline'] }}</strong>
                        <div class="fi-color-gray" style="font-size:.875rem;">{{ $c['detail'] }}</div>
                        @if ($c['url'])<a href="{{ $c['url'] }}" style="font-size:.875rem; text-decoration:underline;">Open</a>@endif
                    </li>
                @endforeach
            </ul>
        </x-filament::section>
    @endif

    @if ($attention !== [])
        <x-filament::section>
            <x-slot name="heading"><span style="color:var(--warning-600,#b45309);">Worth attention</span></x-slot>
            <ul style="margin:0; padding-left:1rem; display:grid; gap:.75rem;">
                @foreach ($attention as $c)
                    <li>
                        <strong>{{ $c['headline'] }}</strong>
                        <div class="fi-color-gray" style="font-size:.875rem;">{{ $c['detail'] }}</div>
                        @if ($c['url'])<a href="{{ $c['url'] }}" style="font-size:.875rem; text-decoration:underline;">Open</a>@endif
                    </li>
                @endforeach
            </ul>
        </x-filament::section>
    @endif

    <x-filament::section>
        <x-slot name="heading">Fix it from</x-slot>
        <div style="display:flex; flex-wrap:wrap; gap:.5rem;">
            <x-filament::button tag="a" color="gray" href="{{ \App\Filament\Resources\Trips\TripResource::getUrl('edit', ['record' => $trip]) }}">Trip page</x-filament::button>
            <x-filament::button tag="a" color="gray" href="{{ \App\Filament\Resources\Trips\TripResource::getUrl('lists', ['record' => $trip]) }}">Lists and cards</x-filament::button>
            <x-filament::button tag="a" color="gray" href="{{ \App\Filament\Resources\Bookings\BookingResource::getUrl() }}?tableFilters[trip_id][value]={{ $trip->id }}">Bookings</x-filament::button>
            <x-filament::button tag="a" color="gray" href="{{ \App\Filament\Resources\Documents\DocumentResource::getUrl() }}">Copies to check</x-filament::button>
        </div>
    </x-filament::section>
</x-filament-panels::page>
