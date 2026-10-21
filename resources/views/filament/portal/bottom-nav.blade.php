<nav class="hz-bottom-nav" aria-label="Quick navigation">
    @foreach ($items as $item)
        <a href="{{ $item['url'] }}" class="hz-bottom-nav__item{{ $item['active'] ? ' is-active' : '' }}" @if ($item['active']) aria-current="page" @endif>
            <span class="hz-bottom-nav__icon" aria-hidden="true">
                @switch($item['icon'])
                    @case('home')
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 11.5 12 4l9 7.5"/><path d="M5 10v10h5v-6h4v6h5V10"/></svg>
                        @break
                    @case('trips')
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 20h18"/><path d="M4 16 20 9l-2-2-5 2-6-3-2 1 4 3-4 2-2-1-1 1z"/></svg>
                        @break
                    @case('pilgrims')
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>
                        @break
                    @case('documents')
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M7 3h7l5 5v13H7z"/><path d="M14 3v5h5"/><path d="m9 15 2 2 4-4"/></svg>
                        @break
                    @case('rates')
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 17l5-5 4 4 7-7"/><path d="M15 9h5v5"/></svg>
                        @break
                    @case('accounts')
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="6" width="18" height="13" rx="2"/><path d="M3 10h18"/><path d="M7 15h4"/></svg>
                        @break
                    @case('islands')
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M3 12h18"/><path d="M12 3a14 14 0 0 1 0 18a14 14 0 0 1 0-18"/></svg>
                        @break
                    @case('telegram')
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 4 3 11l6 2 2 6 3-4 5 3z"/><path d="m9 13 8-7"/></svg>
                        @break
                    @default
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="8"/></svg>
                @endswitch
            </span>
            <span class="hz-bottom-nav__label">{{ $item['label'] }}</span>
        </a>
    @endforeach
</nav>
