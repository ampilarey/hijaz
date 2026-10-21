<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">{{ $today }}</x-slot>
        <x-slot name="description">Welcome, {{ $user->name }}. Nothing is waiting for you yet: trips and pilgrims arrive in the next phases.</x-slot>

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
