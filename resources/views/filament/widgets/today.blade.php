<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">{{ $today }}</x-slot>
        <x-slot name="description">Welcome, {{ $user->name }}.</x-slot>

        @if ($overdue->isNotEmpty())
            <div style="margin-bottom:1rem;">
                <p style="font-weight:600; color:var(--danger-600, #b91c1c);">Overdue checklist items</p>
                <ul style="margin:.25rem 0 0; padding-left:1rem;">
                    @foreach ($overdue as $item)
                        <li><a href="{{ \App\Filament\Resources\Trips\TripResource::getUrl('edit', ['record' => $item->trip]) }}">{{ $item->title }}</a> <span class="fi-color-gray">· {{ $item->trip->name }} · due {{ $item->due_on->format('j M') }}</span></li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($trips->isNotEmpty())
            <div style="margin-bottom:1rem;">
                <p style="font-weight:600;">Upcoming trips</p>
                <ul style="margin:.25rem 0 0; padding-left:1rem;">
                    @foreach ($trips as $trip)
                        <li><a href="{{ \App\Filament\Resources\Trips\TripResource::getUrl('edit', ['record' => $trip]) }}">{{ $trip->name }}</a> <span class="fi-color-gray">· {{ $trip->departs_on->format('j M') }} · {{ $trip->seatsTaken() }} of {{ $trip->capacity_total }} seats · {{ strtolower($trip->statusLabel()) }}</span></li>
                    @endforeach
                </ul>
            </div>
        @elseif ($user->can('trip.viewAny'))
            <p style="margin-bottom:1rem;" class="fi-color-gray">No upcoming trips yet. Create one under Trips.</p>
        @endif

        <dl class="fi-ta-text" style="display:grid; gap:0.75rem;">
            <div>
                <dt style="font-weight:600;">Your access</dt>
                <dd>{{ $roles === [] ? 'No role yet. Ask an Admin.' : implode(', ', $roles) }}</dd>
            </div>
            <div>
                <dt style="font-weight:600;">Authenticator app</dt>
                <dd>{{ $secondFactor ? 'Set up.' : 'Not set up. Add one from your profile menu.' }}</dd>
            </div>
            <div>
                <dt style="font-weight:600;">Telegram</dt>
                <dd>{{ $telegramLinked ? 'Linked. The bot answers you.' : 'Not linked. Open Telegram in the menu to link your account.' }}</dd>
            </div>
        </dl>
    </x-filament::section>
</x-filament-widgets::widget>
