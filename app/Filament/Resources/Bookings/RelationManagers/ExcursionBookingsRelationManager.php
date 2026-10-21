<?php

namespace App\Filament\Resources\Bookings\RelationManagers;

use App\Filament\Support\PaymentActions;
use App\Models\Booking;
use App\Models\BookingPilgrim;
use App\Models\ExcursionBooking;
use App\Models\TripExcursion;
use App\Services\Bookings\Excursions;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ExcursionBookingsRelationManager extends RelationManager
{
    protected static string $relationship = 'excursionBookings';

    protected static ?string $title = 'Additional trips';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        /** @var Booking $booking */
        $booking = $this->getOwnerRecord();

        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['excursion', 'participants.bookingPilgrim.pilgrim']))
            ->columns([
                TextColumn::make('excursion.title')->label('Trip')
                    ->description(fn (ExcursionBooking $record): string => $record->participants->map(fn ($p) => $p->bookingPilgrim?->pilgrim?->name_en)->filter()->implode(', ')),
                TextColumn::make('total_minor')->label('Total')->alignEnd()->state(fn (ExcursionBooking $record): string => $record->total()->format())->visibleFrom('sm'),
                TextColumn::make('status')->badge()
                    ->state(fn (ExcursionBooking $record): string => $record->status === ExcursionBooking::PAID ? 'Paid' : ($record->status === ExcursionBooking::CANCELLED ? 'Cancelled' : $record->balance()->format().' due'))
                    ->color(fn (ExcursionBooking $record): string => match ($record->status) {
                        ExcursionBooking::PAID => 'success', ExcursionBooking::CANCELLED => 'gray', default => 'warning',
                    }),
            ])
            ->headerActions([
                Action::make('book')
                    ->label('Book an additional trip')
                    ->icon('heroicon-o-map')
                    ->visible(fn (): bool => $booking->isLive() && $booking->trip->excursions()->exists())
                    ->authorize(fn (): bool => (bool) Filament::auth()->user()?->can('excursionBooking.create'))
                    ->schema([
                        Select::make('trip_excursion_id')->label('Which trip')->required()->native(false)
                            ->options(fn (): array => $booking->trip->excursions->mapWithKeys(fn (TripExcursion $x): array => [$x->id => $x->title.' · '.$x->priceLabel()])->all()),
                        CheckboxList::make('booking_pilgrim_ids')->label('Who is going')->required()
                            ->options(fn (): array => $booking->pilgrims()->with('pilgrim')->get()->mapWithKeys(fn (BookingPilgrim $bp): array => [$bp->id => $bp->pilgrim->name_en])->all())
                            ->default(fn (): array => $booking->pilgrims()->pluck('id')->all()),
                    ])
                    ->action(function (array $data, Excursions $excursions) use ($booking): void {
                        $excursion = TripExcursion::query()->findOrFail($data['trip_excursion_id']);

                        try {
                            $xb = $excursions->book($excursion, $booking, array_map('intval', $data['booking_pilgrim_ids']));
                        } catch (\InvalidArgumentException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title($xb->reference.' · '.$xb->total()->format())->send();
                    }),
            ])
            ->recordActions([
                ActionGroup::make([
                    PaymentActions::record(fn (?ExcursionBooking $record) => $record, 'Pay')->visible(fn (ExcursionBooking $record): bool => $record->status === ExcursionBooking::OPEN && $record->total_minor > 0),
                    Action::make('invoice')->label('Invoice')->icon('heroicon-o-document-text')->color('gray')
                        ->url(fn (ExcursionBooking $record): string => route('excursion-bookings.invoice', $record), shouldOpenInNewTab: true),
                    Action::make('cancel')->label('Cancel')->icon('heroicon-o-x-circle')->color('danger')
                        ->visible(fn (ExcursionBooking $record): bool => $record->status !== ExcursionBooking::CANCELLED)
                        ->authorize(fn (): bool => (bool) Filament::auth()->user()?->can('excursionBooking.update'))
                        ->requiresConfirmation()
                        ->action(function (ExcursionBooking $record, Excursions $excursions): void {
                            $excursions->cancel($record);
                            Notification::make()->warning()->title('Cancelled')->send();
                        }),
                ]),
            ])
            ->paginated(false)
            ->emptyStateHeading('No additional trips booked')
            ->emptyStateDescription('Taif, Jeddah and the like: booked here, paid in riyal in Saudi Arabia.');
    }
}
