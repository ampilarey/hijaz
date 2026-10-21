<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Scan a card or tag</x-slot>
        <x-slot name="description">Point the phone's camera at the QR and it opens here. Or type the serial printed under it.</x-slot>
        <form wire:submit="lookup" style="display:flex; gap:.5rem; flex-wrap:wrap; align-items:center;">
            <x-filament::input.wrapper style="flex:1; min-width:12rem;">
                <x-filament::input type="text" wire:model="code" placeholder="HJZ-C-000012" autocomplete="off" autocapitalize="characters" />
            </x-filament::input.wrapper>
            <x-filament::button type="submit">Find</x-filament::button>
            <x-filament::button type="button" color="gray" x-data x-show="'BarcodeDetector' in window" x-on:click="$dispatch('hz-scan-start')">Camera</x-filament::button>
        </form>

        <div x-data="hzScanner($wire)" x-on:hz-scan-start.window="start()" x-show="open" x-cloak style="margin-top:1rem;">
            <video x-ref="video" playsinline muted style="width:100%; max-height:60vh; border-radius:.5rem; background:#000;"></video>
            <div style="display:flex; gap:.5rem; margin-top:.5rem;">
                <x-filament::button type="button" color="gray" x-on:click="stop()">Stop camera</x-filament::button>
                <span class="fi-color-gray" x-text="status" style="align-self:center; font-size:.875rem;"></span>
            </div>
        </div>
    </x-filament::section>

    @if ($bp)
        <x-filament::section>
            <x-slot name="heading">{{ $bp->pilgrim->name_en }}</x-slot>
            <x-slot name="description">{{ $card->kindLabel() }} {{ $card->serial }} · {{ $booking->trip->name }} · {{ $booking->trip->code }}</x-slot>
            <dl style="display:grid; grid-template-columns: 1fr 1fr; gap:.75rem;">
                <div><dt class="fi-color-gray" style="font-size:.75rem;">Bus</dt><dd style="font-weight:600; font-size:1.25rem;">{{ $bp->busGroup?->name ?? '—' }}</dd></div>
                <div><dt class="fi-color-gray" style="font-size:.75rem;">On the bus</dt><dd style="font-weight:600; font-size:1.25rem; color:{{ $bp->isCheckedIn() ? 'var(--success-600,#15803d)' : 'inherit' }};">{{ $bp->isCheckedIn() ? 'Yes · '.$bp->checked_in_at->format('H:i') : 'Not yet' }}</dd></div>
                @foreach ($rooms as $room)
                    <div><dt class="fi-color-gray" style="font-size:.75rem;">{{ $room['city'] }} room</dt><dd style="font-weight:600;">{{ $room['room'] ?? '—' }}</dd></div>
                @endforeach
                <div><dt class="fi-color-gray" style="font-size:.75rem;">Family</dt><dd>{{ $booking->customer->name }}</dd></div>
                <div><dt class="fi-color-gray" style="font-size:.75rem;">Booking</dt><dd>{{ $booking->reference }} · {{ $booking->statusLabel() }}</dd></div>
                @if ($canSeeMoney)
                    <div><dt class="fi-color-gray" style="font-size:.75rem;">Money</dt><dd style="color:{{ $booking->isPaidUp() ? 'var(--success-600,#15803d)' : 'var(--danger-600,#b91c1c)' }};">{{ ucfirst($balance) }}</dd></div>
                @endif
                <div><dt class="fi-color-gray" style="font-size:.75rem;">Visa</dt><dd>{{ $bp->visaLabel() }}</dd></div>
                <div><dt class="fi-color-gray" style="font-size:.75rem;">Copies</dt><dd>{{ collect($docs)->map(fn ($d) => $d['label'].' '.$d['state'])->implode(' · ') }}</dd></div>
                @if ($bp->pilgrim->phone)<div><dt class="fi-color-gray" style="font-size:.75rem;">Phone</dt><dd><a href="tel:{{ $bp->pilgrim->phone }}">{{ $bp->pilgrim->phone }}</a></dd></div>@endif
                @if ($bp->pilgrim->needs)<div><dt class="fi-color-gray" style="font-size:.75rem;">Needs</dt><dd>{{ collect($bp->pilgrim->needs)->filter()->keys()->map(fn ($k) => \App\Models\Pilgrim::NEEDS[$k] ?? $k)->implode(', ') }}</dd></div>@endif
            </dl>
            <div style="display:flex; flex-wrap:wrap; gap:.5rem; margin-top:1rem;">
                @if ($canCheckIn && $bp->busGroup)
                    <x-filament::button wire:click="checkIn" :color="$bp->isCheckedIn() ? 'gray' : 'success'">{{ $bp->isCheckedIn() ? 'Undo check-in' : 'Check in to '.$bp->busGroup->name }}</x-filament::button>
                @endif
                <x-filament::button tag="a" color="gray" href="{{ \App\Filament\Resources\Pilgrims\PilgrimResource::getUrl('edit', ['record' => $bp->pilgrim_id]) }}">Pilgrim</x-filament::button>
                @if ($canSeeMoney)
                    <x-filament::button tag="a" color="gray" href="{{ \App\Filament\Resources\Bookings\BookingResource::getUrl('view', ['record' => $booking]) }}">Booking</x-filament::button>
                @endif
                <x-filament::button wire:click="clear" color="gray">Next</x-filament::button>
            </div>
        </x-filament::section>
    @endif

    @push('scripts')
    <script>
        // The browser's own QR reader (Chrome on Android). Phones without it use the camera app: the QR is a link to this page.
        document.addEventListener('alpine:init', () => {
            Alpine.data('hzScanner', ($wire) => ({
                open: false, status: '', stream: null, timer: null,
                async start() {
                    if (!('BarcodeDetector' in window)) { return; }
                    this.open = true; this.status = 'Looking for a QR…';
                    try {
                        this.stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
                        this.$refs.video.srcObject = this.stream;
                        await this.$refs.video.play();
                        const detector = new BarcodeDetector({ formats: ['qr_code'] });
                        this.timer = setInterval(async () => {
                            try {
                                const codes = await detector.detect(this.$refs.video);
                                if (codes.length > 0) {
                                    const value = codes[0].rawValue;
                                    this.stop();
                                    await $wire.set('code', value);
                                    await $wire.lookup();
                                }
                            } catch (e) { this.status = 'Could not read: ' + e.message; }
                        }, 400);
                    } catch (e) { this.status = 'Camera not available: ' + e.message; }
                },
                stop() {
                    if (this.timer) { clearInterval(this.timer); this.timer = null; }
                    if (this.stream) { this.stream.getTracks().forEach(t => t.stop()); this.stream = null; }
                    this.open = false; this.status = '';
                },
            }));
        });
    </script>
    @endpush
</x-filament-panels::page>
