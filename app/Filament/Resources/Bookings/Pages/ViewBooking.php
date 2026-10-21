<?php

namespace App\Filament\Resources\Bookings\Pages;

use App\Exceptions\IllegalBookingTransition;
use App\Exceptions\NoSeatsAvailable;
use App\Filament\Resources\Bookings\BookingResource;
use App\Models\Booking;
use App\Models\Discount;
use App\Services\Bookings\BookingDesk;
use App\Support\Currency;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewBooking extends ViewRecord
{
    protected static string $resource = BookingResource::class;

    public function getTitle(): string
    {
        /** @var Booking $b */
        $b = $this->getRecord();

        return $b->reference;
    }

    public function getSubheading(): ?string
    {
        /** @var Booking $b */
        $b = $this->getRecord();

        return $b->customer->name.' · '.$b->trip->name.' · '.$b->balanceSummary();
    }

    protected function getHeaderActions(): array
    {
        /** @var Booking $booking */
        $booking = $this->getRecord();

        return [
            Action::make('confirm')
                ->label('Confirm booking')
                ->icon('heroicon-o-check')
                ->color('success')
                ->visible(fn (): bool => $booking->status === Booking::DRAFT)
                ->authorize('confirm', $booking)
                ->requiresConfirmation()
                ->modalDescription('The seats move from held to confirmed. Do this once the deposit is in or the customer has committed.')
                ->action(function (BookingDesk $desk) use ($booking): void {
                    try {
                        $desk->confirm($booking);
                    } catch (IllegalBookingTransition|NoSeatsAvailable $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();

                        return;
                    }

                    Notification::make()->success()->title('Confirmed')->send();
                    $this->redirect(BookingResource::getUrl('view', ['record' => $booking]));
                }),

            Action::make('invoice')
                ->label('Invoice')
                ->icon('heroicon-o-document-text')
                ->color('gray')
                ->authorize('view', $booking)
                ->url(fn (): string => route('bookings.invoice', $booking), shouldOpenInNewTab: true),

            ActionGroup::make([
                Action::make('discount')
                    ->label('Give a discount')
                    ->icon('heroicon-o-receipt-percent')
                    ->visible(fn (): bool => $booking->isLive() && $booking->currencies() !== [])
                    ->authorize('discount', $booking)
                    ->schema([
                        Select::make('kind')->options([Discount::FIXED => 'An amount', Discount::PERCENT => 'A percentage'])->default(Discount::FIXED)->required()->native(false)->live(),
                        Select::make('currency')->label('Off which balance')->options(array_intersect_key(Currency::options(), array_flip($booking->currencies())))->default($booking->currencies()[0] ?? Currency::MVR)->required()->native(false),
                        TextInput::make('value')->label(fn ($get): string => $get('kind') === Discount::PERCENT ? 'Percent' : 'Amount')->numeric()->required()->minValue(0.01)->step(0.01),
                        Textarea::make('reason')->required()->rows(2)->placeholder('Returning pilgrim / group of ten / agreed by the owner'),
                    ])
                    ->action(function (array $data, BookingDesk $desk) use ($booking): void {
                        try {
                            $d = $desk->discount($booking, $data['kind'], (string) $data['value'], $data['currency'], $data['reason']);
                        } catch (\InvalidArgumentException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title($d->money()->format().' off')->send();
                        $this->redirect(BookingResource::getUrl('view', ['record' => $booking]));
                    }),

                Action::make('extend_hold')
                    ->label('Hold the seats longer')
                    ->icon('heroicon-o-clock')
                    ->visible(fn (): bool => $booking->status === Booking::DRAFT)
                    ->authorize('update', $booking)
                    ->schema([TextInput::make('days')->label('For how many more days')->integer()->minValue(1)->maxValue(60)->default(7)->required()])
                    ->action(function (array $data, BookingDesk $desk) use ($booking): void {
                        try {
                            $desk->extendHold($booking, (int) $data['days']);
                        } catch (NoSeatsAvailable|\InvalidArgumentException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title('Seats held for '.$data['days'].' more days')->send();
                    }),

                Action::make('complete')
                    ->label('Mark as travelled')
                    ->icon('heroicon-o-flag')
                    ->visible(fn (): bool => $booking->status === Booking::CONFIRMED)
                    ->authorize('confirm', $booking)
                    ->requiresConfirmation()
                    ->action(function (BookingDesk $desk) use ($booking): void {
                        $desk->complete($booking);
                        Notification::make()->success()->title('Completed')->send();
                        $this->redirect(BookingResource::getUrl('view', ['record' => $booking]));
                    }),

                EditAction::make()->label('Edit notes'),

                Action::make('cancel')
                    ->label('Cancel booking')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (): bool => $booking->isLive())
                    ->authorize('cancel', $booking)
                    ->requiresConfirmation()
                    ->modalDescription(fn (): string => $booking->isPaidUp() && $booking->currencies() !== [] ? 'The seats go back to the pool. Money already received stays until Finance refunds it.' : 'The seats go back to the pool.')
                    ->schema([Textarea::make('reason')->required()->rows(2)])
                    ->action(function (array $data, BookingDesk $desk) use ($booking): void {
                        try {
                            $desk->cancel($booking, $data['reason']);
                        } catch (IllegalBookingTransition $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->warning()->title('Cancelled')->send();
                        $this->redirect(BookingResource::getUrl('view', ['record' => $booking]));
                    }),
            ])->label('More')->icon('heroicon-o-ellipsis-horizontal')->button()->color('gray'),
        ];
    }
}
