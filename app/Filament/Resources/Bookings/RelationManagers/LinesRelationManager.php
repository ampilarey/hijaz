<?php

namespace App\Filament\Resources\Bookings\RelationManagers;

use App\Filament\Forms\MoneyInput;
use App\Models\Booking;
use App\Models\BookingLine;
use App\Models\Discount;
use App\Services\Bookings\BookingDesk;
use App\Support\Currency;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LinesRelationManager extends RelationManager
{
    protected static string $relationship = 'lines';

    protected static ?string $title = 'Charges';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        /** @var Booking $booking */
        $booking = $this->getOwnerRecord();

        return $table
            ->columns([
                TextColumn::make('description')
                    ->description(fn (BookingLine $record): ?string => $record->quantity > 1 ? $record->quantity.' × '.Money::ofMinor($record->unit_amount_minor, $record->currency)->format() : null),
                TextColumn::make('type')->badge()->state(fn (BookingLine $record): string => BookingLine::TYPES[$record->type] ?? $record->type)
                    ->color(fn (BookingLine $record): string => $record->type === BookingLine::DISCOUNT ? 'success' : 'gray')->visibleFrom('sm'),
                TextColumn::make('amount_minor')->label('Amount')->alignEnd()->state(fn (BookingLine $record): string => $record->money()->format())
                    ->color(fn (BookingLine $record): ?string => $record->amount_minor < 0 ? 'success' : null),
            ])
            ->headerActions([
                Action::make('add_line')
                    ->label('Add a charge')
                    ->icon('heroicon-o-plus')
                    ->visible(fn (): bool => $booking->isLive())
                    ->authorize($this->may('update'))
                    ->schema([
                        Select::make('type')->options([BookingLine::EXTRA => 'Extra (something bought)', BookingLine::FEE => 'Fee (visa, insurance, change)'])->default(BookingLine::EXTRA)->required()->native(false),
                        TextInput::make('description')->required()->maxLength(160)->placeholder('Visa fee / Zamzam 5L / Wheelchair at the airport'),
                        Select::make('currency')->options(Currency::options())->default(Currency::MVR)->required()->native(false)->live(),
                        MoneyInput::make('unit_amount_minor', 'currency', 'Each'),
                        TextInput::make('quantity')->integer()->minValue(1)->default(1)->required(),
                    ])
                    ->action(function (array $data, BookingDesk $desk) use ($booking): void {
                        $desk->addLine($booking, $data['type'], $data['description'], Money::ofMinor((int) $data['unit_amount_minor'], $data['currency']), (int) $data['quantity']);
                        Notification::make()->success()->title('Added')->send();
                    }),
            ])
            ->recordActions([
                Action::make('remove')
                    ->label('Remove')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->visible(fn (BookingLine $record): bool => $booking->isLive() && in_array($record->type, [BookingLine::EXTRA, BookingLine::FEE], true))
                    ->authorize($this->may('update'))
                    ->requiresConfirmation()
                    ->action(function (BookingLine $record): void {
                        $record->delete();
                        Notification::make()->success()->title('Removed')->send();
                    }),
                Action::make('reverse_discount')
                    ->label('Take back')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('warning')
                    ->visible(fn (BookingLine $record): bool => $booking->isLive() && $record->type === BookingLine::DISCOUNT)
                    ->authorize($this->may('discount'))
                    ->requiresConfirmation()
                    ->action(function (BookingLine $record, BookingDesk $desk): void {
                        $discount = Discount::query()->where('booking_line_id', $record->getKey())->first();

                        if ($discount instanceof Discount) {
                            $desk->reverseDiscount($discount);
                        } else {
                            $record->delete();
                        }

                        Notification::make()->success()->title('Discount reversed')->send();
                    }),
            ])
            ->paginated(false)
            ->emptyStateHeading('Nothing priced yet')
            ->emptyStateDescription('Add a traveller and the fare appears here; add fees and extras as needed.');
    }

    /**
     * Filament checks a relation manager's actions against the related
     * model, so the owner's own policy is asked by hand.
     */
    private function may(string $ability): \Closure
    {
        return fn (): bool => (bool) Filament::auth()->user()?->can($ability, $this->getOwnerRecord());
    }
}
