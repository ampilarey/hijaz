<?php

namespace App\Filament\Resources\Bookings\RelationManagers;

use App\Exceptions\NoSeatsAvailable;
use App\Filament\Resources\Pilgrims\PilgrimResource;
use App\Models\Booking;
use App\Models\BookingPilgrim;
use App\Models\Pilgrim;
use App\Models\PriceTier;
use App\Services\Bookings\BookingDesk;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PilgrimsRelationManager extends RelationManager
{
    protected static string $relationship = 'pilgrims';

    protected static ?string $title = 'Travellers';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('ticket_number')->label('Ticket number')->maxLength(30),
            Select::make('visa_status')->label('Visa')->options(BookingPilgrim::VISA_STATUSES)->native(false)->required(),
        ]);
    }

    public function table(Table $table): Table
    {
        /** @var Booking $booking */
        $booking = $this->getOwnerRecord();

        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('pilgrim'))
            ->columns([
                TextColumn::make('pilgrim.name_en')->label('Name')
                    ->description(fn (BookingPilgrim $record): string => implode(' · ', array_filter([
                        $record->is_lead ? 'lead' : null,
                        PriceTier::OCCUPANCIES[$record->occupancy] ?? $record->occupancy,
                        $record->pax_type !== PriceTier::ADULT ? $record->pax_type : null,
                        $record->pilgrim->passportOkFor($booking->trip->returns_on) === false ? 'passport expires too soon' : null,
                    ]))),
                TextColumn::make('amount_minor')->label('Fare')->alignEnd()->state(fn (BookingPilgrim $record): string => $record->money()->format()),
                TextColumn::make('visa_status')->label('Visa')->badge()->state(fn (BookingPilgrim $record): string => BookingPilgrim::VISA_STATUSES[$record->visa_status] ?? $record->visa_status)
                    ->color(fn (BookingPilgrim $record): string => match ($record->visa_status) {
                        'issued' => 'success', 'rejected' => 'danger', 'submitted' => 'info', default => 'gray',
                    })->visibleFrom('sm'),
                TextColumn::make('ticket_number')->label('Ticket')->visibleFrom('md'),
            ])
            ->headerActions([
                Action::make('add')
                    ->label('Add a traveller')
                    ->icon('heroicon-o-user-plus')
                    ->visible(fn (): bool => $booking->isLive())
                    ->authorize($this->may('update'))
                    ->schema([
                        Select::make('pilgrim_id')->label('Pilgrim')->required()->native(false)->searchable()
                            ->options(fn (): array => Pilgrim::query()->where('customer_id', $booking->customer_id)
                                ->whereNotIn('id', $booking->pilgrims()->pluck('pilgrim_id'))
                                ->orderBy('name_en')->get()->mapWithKeys(fn (Pilgrim $p): array => [$p->id => $p->name_en])->all())
                            ->helperText('Someone from another family is booked on their own customer\'s booking.'),
                        Select::make('occupancy')->label('Room')->required()->native(false)
                            ->options(fn (): array => array_intersect_key(PriceTier::OCCUPANCIES, array_flip($booking->trip->priceTiers->pluck('occupancy')->unique()->all())) ?: PriceTier::OCCUPANCIES),
                    ])
                    ->action(function (array $data, BookingDesk $desk) use ($booking): void {
                        try {
                            $desk->addPilgrim($booking, Pilgrim::query()->findOrFail($data['pilgrim_id']), $data['occupancy']);
                        } catch (NoSeatsAvailable|\InvalidArgumentException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title('Added')->send();
                        $this->dispatch('refresh-booking');
                    }),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make()->label('Ticket / visa')->authorize($this->may('update')),
                    Action::make('open')->label('Pilgrim')->icon('heroicon-o-user')->color('gray')
                        ->url(fn (BookingPilgrim $record): string => PilgrimResource::getUrl('edit', ['record' => $record->pilgrim])),
                    Action::make('remove')
                        ->label('Remove')
                        ->icon('heroicon-o-user-minus')
                        ->color('danger')
                        ->visible(fn (): bool => $booking->isLive() && $booking->pilgrims()->count() > 1)
                        ->authorize($this->may('update'))
                        ->requiresConfirmation()
                        ->modalDescription('Their seat goes back to the pool and their fare comes off the booking.')
                        ->action(function (BookingPilgrim $record, BookingDesk $desk): void {
                            $desk->removePilgrim($record);
                            Notification::make()->success()->title('Removed')->send();
                        }),
                ]),
            ])
            ->emptyStateHeading('Nobody on this booking');
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
