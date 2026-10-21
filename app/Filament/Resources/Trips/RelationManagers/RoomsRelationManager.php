<?php

namespace App\Filament\Resources\Trips\RelationManagers;

use App\Models\Booking;
use App\Models\BookingPilgrim;
use App\Models\Room;
use App\Models\Trip;
use App\Models\TripHotel;
use App\Services\Operations\Rooming;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Every room in every hotel of the trip, with who is in it and what the checker says. */
class RoomsRelationManager extends RelationManager
{
    protected static string $relationship = 'rooms';

    protected static ?string $title = 'Rooms';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return (bool) Filament::auth()->user()?->can('room.viewAny');
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        /** @var Trip $trip */
        $trip = $this->getOwnerRecord();

        return $schema->columns(2)->components([
            Select::make('trip_hotel_id')->label('Hotel')->required()->native(false)
                ->options(fn (): array => $trip->hotels()->orderBy('check_in_on')->get()->mapWithKeys(fn (TripHotel $h): array => [$h->id => $h->cityLabel().' · '.$h->name])->all()),
            TextInput::make('label')->label('Room number')->required()->maxLength(20),
            TextInput::make('capacity')->label('Beds')->integer()->required()->minValue(1)->maxValue(12),
            Select::make('gender')->label('For')->options(Room::GENDERS)->native(false)->helperText('A family room is mixed on purpose.'),
            TextInput::make('notes')->maxLength(255)->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        /** @var Trip $trip */
        $trip = $this->getOwnerRecord();

        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['hotel', 'assignments.bookingPilgrim.pilgrim']))
            ->columns([
                TextColumn::make('label')->label('Room')
                    ->description(fn (Room $record): string => $record->hotel->cityLabel().' · '.$record->hotel->name),
                TextColumn::make('capacity')->label('Beds')->state(fn (Room $record): string => $record->assignments->count().' of '.$record->capacity.' · '.strtolower($record->genderLabel()))
                    ->color(fn (Room $record): ?string => $record->isOverfull() ? 'danger' : null),
                TextColumn::make('who')->label('Who')->state(fn (Room $record): string => $record->occupants()->map(fn (BookingPilgrim $bp) => $bp->pilgrim->name_en)->implode(', ') ?: '—')->wrap()->visibleFrom('sm'),
            ])
            ->defaultSort('label')
            ->filters([
                SelectFilter::make('trip_hotel_id')->label('Hotel')->options(fn (): array => $trip->hotels()->orderBy('check_in_on')->get()->mapWithKeys(fn (TripHotel $h): array => [$h->id => $h->cityLabel().' · '.$h->name])->all()),
            ])
            ->headerActions([
                CreateAction::make()->label('Add a room')->authorize($this->may('room.create'))
                    ->visible(fn (): bool => $trip->hotels()->exists()),
                Action::make('room_family')
                    ->label('Room a family')
                    ->icon('heroicon-o-home')
                    ->color('gray')
                    ->authorize($this->may('room.assign'))
                    ->visible(fn (): bool => $trip->hotels()->exists())
                    ->schema([
                        Select::make('booking_id')->label('Booking')->required()->native(false)->searchable()
                            ->options(fn (): array => $trip->bookings()->where('status', Booking::CONFIRMED)->with(['customer', 'pilgrims'])->get()->mapWithKeys(fn (Booking $b): array => [$b->id => $b->customer->name.' · '.$b->pilgrims->count().' people · '.$b->reference])->all()),
                        Select::make('trip_hotel_id')->label('Hotel')->required()->native(false)
                            ->options(fn (): array => $trip->hotels()->orderBy('check_in_on')->get()->mapWithKeys(fn (TripHotel $h): array => [$h->id => $h->cityLabel().' · '.$h->name])->all()),
                    ])
                    ->action(function (array $data, Rooming $rooming) use ($trip): void {
                        $booking = $trip->bookings()->findOrFail($data['booking_id']);
                        $hotel = $trip->hotels()->findOrFail($data['trip_hotel_id']);
                        $room = $rooming->roomFamily($booking, $hotel);
                        Notification::make()->success()->title($booking->customer->name.' are in room '.$room->label.' at '.$hotel->name)->send();
                    }),
                Action::make('check')
                    ->label('Check rooming')
                    ->icon('heroicon-o-clipboard-document-check')
                    ->color('gray')
                    ->visible(fn (): bool => $trip->hotels()->exists())
                    ->action(function (Rooming $rooming) use ($trip): void {
                        $lines = [];

                        foreach ($trip->hotels as $hotel) {
                            foreach ($rooming->problemsWith($hotel) as $p) {
                                $lines[] = $hotel->cityLabel().($p['room'] ? ' '.$p['room'] : '').': '.$p['detail'];
                            }
                        }

                        Notification::make()->{$lines === [] ? 'success' : 'warning'}()
                            ->title($lines === [] ? 'Rooming is settled' : count($lines).' problem'.(count($lines) === 1 ? '' : 's'))
                            ->body(implode("\n", array_slice($lines, 0, 12)))
                            ->persistent()->send();
                    }),
            ])
            ->recordActions([
                Action::make('assign')
                    ->label('Put people in')
                    ->icon('heroicon-o-user-plus')
                    ->authorize($this->may('room.assign'))
                    ->modalWidth('2xl')
                    ->schema([
                        CheckboxList::make('ids')->label('Travellers')->required()->searchable()->bulkToggleable()
                            ->options(fn (Room $record): array => $trip->travellers()->with(['pilgrim', 'booking.customer', 'roomAssignments.room'])->get()
                                ->reject(fn (BookingPilgrim $bp): bool => $bp->roomIn($record->hotel)?->is($record) ?? false)
                                ->sortBy(fn (BookingPilgrim $bp) => $bp->booking->customer->name.' '.$bp->pilgrim->name_en)
                                ->mapWithKeys(fn (BookingPilgrim $bp): array => [$bp->id => $bp->pilgrim->name_en.' · '.$bp->booking->customer->name.' · '.$bp->occupancy.(($r = $bp->roomIn($record->hotel)) ? ' · now in '.$r->label : '')])->all()),
                    ])
                    ->action(function (Room $record, array $data, Rooming $rooming): void {
                        $result = $rooming->assign($record, array_map('intval', $data['ids']));
                        $problems = array_filter($rooming->problemsWith($record->hotel->fresh()), fn (array $p): bool => $p['room'] === $record->label);
                        Notification::make()->{$problems === [] ? 'success' : 'warning'}()
                            ->title($result['assigned'].' put in room '.$record->label)
                            ->body(implode(' ', array_column($problems, 'detail')) ?: null)
                            ->send();
                    }),
                Action::make('empty')
                    ->label('Empty')
                    ->icon('heroicon-o-user-minus')
                    ->color('gray')
                    ->authorize($this->may('room.assign'))
                    ->visible(fn (Room $record): bool => $record->assignments->isNotEmpty())
                    ->requiresConfirmation()
                    ->action(function (Room $record): void {
                        $record->assignments()->delete();
                        Notification::make()->success()->title('Room '.$record->label.' emptied')->send();
                    }),
                EditAction::make()->authorize($this->may('room.update')),
                DeleteAction::make()->authorize($this->may('room.delete')),
            ])
            ->emptyStateHeading('No rooms yet')
            ->emptyStateDescription('Add the rooms the hotel gave you, then put people in them. "Room a family" does a whole booking in one go.');
    }

    private function may(string $permission): \Closure
    {
        return fn (): bool => (bool) Filament::auth()->user()?->can($permission);
    }
}
