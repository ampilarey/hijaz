<?php

namespace App\Filament\Pages;

use App\Models\Booking;
use App\Models\BookingPilgrim;
use App\Models\Card;
use App\Models\Document;
use App\Services\Operations\Cards;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Point the phone at a card or tag. The QR holds a token; this page turns
 * it back into the traveller and shows what the person at the bus door
 * needs: who, which bus, which room, paid or not, and a check-in button.
 */
class Scan extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-qr-code';

    protected static ?int $navigationSort = 5;

    protected string $view = 'filament.pages.scan';

    public string $code = '';

    public ?int $cardId = null;

    public static function canAccess(): bool
    {
        return (bool) Filament::auth()->user()?->can('card.scan');
    }

    public function getTitle(): string|Htmlable
    {
        return 'Scan';
    }

    public function mount(): void
    {
        $token = (string) request()->query('t', '');

        if ($token !== '') {
            $this->code = $token;
            $this->lookup();
        }
    }

    public function lookup(): void
    {
        $card = app(Cards::class)->resolve($this->code);

        if ($card === null) {
            $this->cardId = null;
            Notification::make()->danger()->title('No card matches that')->body('Type the serial printed under the QR, like HJZ-C-000012.')->send();

            return;
        }

        $this->cardId = $card->getKey();
    }

    public function clear(): void
    {
        $this->code = '';
        $this->cardId = null;
    }

    public function checkIn(): void
    {
        $bp = $this->traveller();

        if ($bp === null || ! Filament::auth()->user()?->can('pilgrim.checkIn')) {
            return;
        }

        $bp->forceFill(['checked_in_at' => $bp->isCheckedIn() ? null : now()])->save();
        Notification::make()->success()->title($bp->isCheckedIn() ? $bp->pilgrim->name_en.' is on the bus' : 'Check-in undone')->send();
    }

    public function card(): ?Card
    {
        return $this->cardId === null ? null : Card::query()->with('bookingPilgrim')->find($this->cardId);
    }

    public function traveller(): ?BookingPilgrim
    {
        return $this->card()?->bookingPilgrim?->load(['pilgrim.documents.versions', 'booking.trip.hotels', 'booking.customer', 'busGroup', 'roomAssignments.room']);
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $bp = $this->traveller();
        $booking = $bp?->booking;

        return [
            'card' => $this->card(),
            'bp' => $bp,
            'booking' => $booking,
            'rooms' => $bp ? $booking->trip->hotels->map(fn ($h) => ['city' => $h->cityLabel(), 'room' => $bp->roomIn($h)?->label])->all() : [],
            'docs' => $bp ? array_map(fn (string $t): array => ['label' => Document::TYPES[$t], 'state' => match (true) {
                ($d = $bp->pilgrim->document($t)) === null || $d->currentVersion() === null => 'missing',
                $d->isVerified() => 'verified',
                default => 'to check',
            }], Document::REQUIRED_FOR_PILGRIMS) : [],
            'balance' => $booking instanceof Booking ? $booking->balanceSummary() : null,
            'canCheckIn' => (bool) Filament::auth()->user()?->can('pilgrim.checkIn'),
            'canSeeMoney' => (bool) Filament::auth()->user()?->can('booking.view'),
        ];
    }
}
