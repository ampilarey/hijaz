<x-filament-panels::page>
    @if (! $this->botConfigured())
        <x-filament::section heading="The bot is not set up yet">
            <p>Create a bot with @BotFather and put its token in the server's <code>.env</code> as <code>TELEGRAM_BOT_TOKEN</code>. This page comes alive on the next deploy.</p>
        </x-filament::section>
    @else
        @php($link = $this->link())
        <x-filament::section :heading="$link ? 'Linked' : 'Not linked'">
            @if ($link)
                <p>This account acts in Telegram as <strong>{{ $link->telegram_name ?? 'chat '.$link->chat_id }}</strong>@if ($link->telegram_username) (&#64;{{ $link->telegram_username }})@endif, linked {{ $link->linked_at->diffForHumans() }}.</p>
                <p class="fi-color-gray" style="margin-top:.5rem">Send <code>/help</code> to the bot to see what it can do.</p>
            @else
                <p>Tap <strong>Get a link code</strong>, then open the link on your phone and press Start. The code lasts {{ config('telegram.link_code_minutes') }} minutes.</p>
            @endif
        </x-filament::section>

        @if ($this->code)
            <x-filament::section heading="Your link code">
                <p style="font-size:1.75rem; letter-spacing:.2em; font-weight:700;">{{ $this->code }}</p>
                @if ($this->deepLink)
                    <p style="margin-top:.75rem"><x-filament::button tag="a" :href="$this->deepLink" target="_blank" rel="noopener">Open Telegram and link</x-filament::button></p>
                    <p class="fi-color-gray" style="margin-top:.5rem">Or send <code>/start {{ $this->code }}</code> to &#64;{{ $this->botUsername() }} yourself.</p>
                @else
                    <p style="margin-top:.5rem">Send <code>/start {{ $this->code }}</code> to the Hijaz bot in Telegram.</p>
                @endif
            </x-filament::section>
        @endif
    @endif
</x-filament-panels::page>
