{{-- The first screen of the day: what needs a hand, each line a thumb-sized row that opens the place to do it. --}}
<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">{{ $today }}</x-slot>
        <x-slot name="description">Welcome, {{ $user->name }}.</x-slot>

        @php
            $chevron = '<svg class="hz-row__chevron" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 0 1 .02-1.06L11.17 10 7.23 6.29a.75.75 0 1 1 1.04-1.08l4.5 4.25a.75.75 0 0 1 0 1.08l-4.5 4.25a.75.75 0 0 1-1.06-.02Z" clip-rule="evenodd"/></svg>';
        @endphp

        @if ($slipsWaiting + $holdsExpiring + $instalmentsOverdue > 0)
            <p class="hz-group-title">Money</p>
            <div class="hz-rows">
                @if ($slipsWaiting > 0)
                    <a class="hz-row" href="{{ \App\Filament\Resources\Payments\PaymentResource::getUrl() }}?activeTab=review">
                        <span class="hz-row__count">{{ $slipsWaiting }}</span>
                        <span class="hz-row__text">{{ Str::plural('payment', $slipsWaiting) }} to verify</span>
                        {!! $chevron !!}
                    </a>
                @endif
                @if ($holdsExpiring > 0)
                    <a class="hz-row" href="{{ \App\Filament\Resources\Bookings\BookingResource::getUrl() }}?activeTab=drafts">
                        <span class="hz-row__count">{{ $holdsExpiring }}</span>
                        <span class="hz-row__text">{{ Str::plural('booking', $holdsExpiring) }} whose seats lapse within two days</span>
                        {!! $chevron !!}
                    </a>
                @endif
                @if ($instalmentsOverdue > 0)
                    <a class="hz-row hz-row--danger" href="{{ \App\Filament\Resources\Bookings\BookingResource::getUrl() }}?activeTab=unpaid">
                        <span class="hz-row__count">{{ $instalmentsOverdue }}</span>
                        <span class="hz-row__text">{{ Str::plural('instalment', $instalmentsOverdue) }} overdue</span>
                        {!! $chevron !!}
                    </a>
                @endif
            </div>
        @endif

        @if ($toAccept + $toVerify + $mismatches + $unverified > 0)
            <p class="hz-group-title">Copies and pilgrims</p>
            <div class="hz-rows">
                @if ($toAccept > 0)
                    <a class="hz-row" href="{{ \App\Filament\Resources\Documents\DocumentResource::getUrl() }}?activeTab=accept">
                        <span class="hz-row__count">{{ $toAccept }}</span>
                        <span class="hz-row__text">{{ Str::plural('reading', $toAccept) }} to accept</span>
                        {!! $chevron !!}
                    </a>
                @endif
                @if ($toVerify > 0)
                    <a class="hz-row" href="{{ \App\Filament\Resources\Documents\DocumentResource::getUrl() }}?activeTab=verify">
                        <span class="hz-row__count">{{ $toVerify }}</span>
                        <span class="hz-row__text">{{ Str::plural('copy', $toVerify) }} to verify</span>
                        {!! $chevron !!}
                    </a>
                @endif
                @if ($mismatches > 0)
                    <a class="hz-row hz-row--danger" href="{{ \App\Filament\Resources\Pilgrims\PilgrimResource::getUrl() }}?tableFilters[mismatch][isActive]=1">
                        <span class="hz-row__count">{{ $mismatches }}</span>
                        <span class="hz-row__text">{{ Str::plural('pilgrim', $mismatches) }} whose ID card and passport disagree</span>
                        {!! $chevron !!}
                    </a>
                @endif
                @if ($unverified > 0)
                    <a class="hz-row" href="{{ \App\Filament\Resources\Pilgrims\PilgrimResource::getUrl() }}">
                        <span class="hz-row__count hz-row__count--quiet">{{ $unverified }}</span>
                        <span class="hz-row__text">{{ Str::plural('registration', $unverified) }} not yet verified</span>
                        {!! $chevron !!}
                    </a>
                @endif
            </div>
        @endif

        @if ($readiness->isNotEmpty())
            <p class="hz-group-title hz-group-title--danger">Trips not ready to travel</p>
            <div class="hz-rows">
                @foreach ($readiness as $r)
                    <a class="hz-row" href="{{ \App\Filament\Resources\Trips\TripResource::getUrl('readiness', ['record' => $r['trip']]) }}">
                        <span class="hz-row__text">
                            <span class="hz-row__title">{{ $r['trip']->code }} · departs {{ $r['trip']->departs_on->format('j M') }}</span>
                            <span class="hz-row__hint">{{ implode('; ', $r['blockers']) }}</span>
                        </span>
                        {!! $chevron !!}
                    </a>
                @endforeach
            </div>
        @endif

        @if ($overdue->isNotEmpty())
            <p class="hz-group-title hz-group-title--danger">Overdue checklist items</p>
            <div class="hz-rows">
                @foreach ($overdue as $item)
                    <a class="hz-row" href="{{ \App\Filament\Resources\Trips\TripResource::getUrl('edit', ['record' => $item->trip]) }}">
                        <span class="hz-row__text">
                            <span class="hz-row__title">{{ $item->title }}</span>
                            <span class="hz-row__hint">{{ $item->trip->name }} · due {{ $item->due_on->format('j M') }}</span>
                        </span>
                        {!! $chevron !!}
                    </a>
                @endforeach
            </div>
        @endif

        @if ($trips->isNotEmpty())
            <p class="hz-group-title">Upcoming trips</p>
            <div class="hz-rows">
                @foreach ($trips as $trip)
                    <a class="hz-row" href="{{ \App\Filament\Resources\Trips\TripResource::getUrl('edit', ['record' => $trip]) }}">
                        <span class="hz-row__text">
                            <span class="hz-row__title">{{ $trip->name }}</span>
                            <span class="hz-row__hint">{{ $trip->departs_on->format('j M') }} · {{ $trip->seatsTaken() }} of {{ $trip->capacity_total }} seats · {{ strtolower($trip->statusLabel()) }}</span>
                        </span>
                        {!! $chevron !!}
                    </a>
                @endforeach
            </div>
        @elseif ($user->can('trip.viewAny'))
            <p class="hz-group-title">Upcoming trips</p>
            <p class="fi-color-gray">No upcoming trips yet. Create one under Trips.</p>
        @endif

        <p class="hz-group-title">You</p>
        <div class="hz-rows">
            <div class="hz-row">
                <span class="hz-row__text"><span class="hz-row__title">Your access</span><span class="hz-row__hint">{{ $roles === [] ? 'No role yet. Ask an Admin.' : implode(', ', $roles) }}</span></span>
            </div>
            <a class="hz-row" href="{{ filament()->getProfileUrl() }}">
                <span class="hz-row__text"><span class="hz-row__title">Authenticator app</span><span class="hz-row__hint">{{ $secondFactor ? 'Set up.' : 'Optional. Add one from your profile if you want a code asked after your password.' }}</span></span>
                {!! $chevron !!}
            </a>
            <a class="hz-row" href="{{ \App\Filament\Pages\Telegram::getUrl() }}">
                <span class="hz-row__text"><span class="hz-row__title">Telegram</span><span class="hz-row__hint">{{ $telegramLinked ? 'Linked. The bot answers you.' : 'Not linked. Link your account so the bot can answer you.' }}</span></span>
                {!! $chevron !!}
            </a>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
