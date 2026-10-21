<?php

namespace App\Filament\Resources\Trips\RelationManagers;

use App\Filament\Forms\MoneyInput;
use App\Filament\Resources\Bookings\BookingResource;
use App\Filament\Resources\Pilgrims\PilgrimResource;
use App\Models\Booking;
use App\Models\BookingPilgrim;
use App\Models\BusGroup;
use App\Models\Document;
use App\Models\Room;
use App\Models\Trip;
use App\Models\TripExcursion;
use App\Models\TripHotel;
use App\Services\Bookings\Excursions;
use App\Services\Operations\Buses;
use App\Services\Operations\Rooming;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Everyone on the trip, one row each, with the bulk actions the office
 * runs down a list: onto a bus, into a room, visa marked, cards printed.
 */
class TravellersRelationManager extends RelationManager
{
    protected static string $relationship = 'travellers';

    protected static ?string $title = 'Travellers';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return (bool) Filament::auth()->user()?->can('booking.viewAny');
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('ticket_number')->label('Ticket number')->maxLength(30),
            TextInput::make('seat_number')->label('Seat')->maxLength(8),
            Select::make('visa_status')->label('Visa')->options(BookingPilgrim::VISA_STATUSES)->native(false)->required(),
            TextInput::make('visa_number')->label('Visa number')->maxLength(40),
            TextInput::make('visa_agent')->label('Agent')->maxLength(80),
            DatePicker::make('visa_submitted_on')->label('Submitted')->native(false),
            DatePicker::make('visa_issued_on')->label('Issued')->native(false),
        ]);
    }

    public function table(Table $table): Table
    {
        /** @var Trip $trip */
        $trip = $this->getOwnerRecord();
        $hotels = $trip->hotels()->orderBy('check_in_on')->get();

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['pilgrim.documents.versions', 'booking.customer', 'busGroup', 'roomAssignments.room']))
            ->columns([
                TextColumn::make('pilgrim.name_en')->label('Name')->searchable()
                    ->description(fn (BookingPilgrim $record): string => $record->booking->customer->name.' · '.$record->booking->reference.($record->booking->status === Booking::DRAFT ? ' · draft' : '')),
                TextColumn::make('busGroup.name')->label('Bus')->placeholder('—')->visibleFrom('sm'),
                TextColumn::make('rooms')->label('Rooms')->state(fn (BookingPilgrim $record): string => $hotels->map(fn (TripHotel $h) => substr($h->cityLabel(), 0, 3).' '.($record->roomIn($h)?->label ?? '—'))->implode(' · '))->visibleFrom('md'),
                TextColumn::make('visa_status')->label('Visa')->badge()->state(fn (BookingPilgrim $record): string => $record->visaLabel())
                    ->color(fn (BookingPilgrim $record): string => match ($record->visa_status) {
                        BookingPilgrim::VISA_ISSUED => 'success', 'rejected' => 'danger', BookingPilgrim::VISA_SUBMITTED => $record->visaIsLate() ? 'danger' : 'info', default => 'gray',
                    }),
                TextColumn::make('docs')->label('Copies')
                    ->state(fn (BookingPilgrim $record): string => implode(' ', array_map(fn (string $t): string => match (true) {
                        ($d = $record->pilgrim->document($t)) === null || $d->currentVersion() === null => '✗',
                        $d->isVerified() => '✓',
                        default => '?',
                    }, Document::REQUIRED_FOR_PILGRIMS)))
                    ->tooltip('ID card · passport · photo: ✓ verified, ? to check, ✗ missing')
                    ->visibleFrom('sm'),
                TextColumn::make('ticket_number')->label('Ticket')->placeholder('—')->visibleFrom('lg'),
                TextColumn::make('checked_in_at')->label('On bus')->state(fn (BookingPilgrim $record): string => $record->isCheckedIn() ? '✓' : '')->visibleFrom('lg'),
            ])
            ->defaultSort('id')
            ->filters([
                SelectFilter::make('bus_group_id')->label('Bus')->options(fn (): array => $trip->busGroups()->pluck('name', 'id')->all()),
                SelectFilter::make('visa_status')->label('Visa')->options(BookingPilgrim::VISA_STATUSES),
                SelectFilter::make('status')->label('Booking')->options([Booking::CONFIRMED => 'Confirmed', Booking::DRAFT => 'Draft'])
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null) ? $query->where('bookings.status', $data['value']) : $query),
            ])
            ->recordActions([
                EditAction::make()->label('Visa / ticket')->authorize($this->may('visa.update')),
                Action::make('open')->label('Pilgrim')->icon('heroicon-o-user')->color('gray')
                    ->url(fn (BookingPilgrim $record): string => PilgrimResource::getUrl('edit', ['record' => $record->pilgrim_id])),
                Action::make('booking')->label('Booking')->icon('heroicon-o-ticket')->color('gray')
                    ->url(fn (BookingPilgrim $record): string => BookingResource::getUrl('view', ['record' => $record->booking_id])),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('bus')
                        ->label('Put on a bus')
                        ->icon('heroicon-o-truck')
                        ->authorize($this->may('bus.assign'))
                        ->schema([Select::make('bus_group_id')->label('Bus')->required()->native(false)->options(fn (): array => $trip->busGroups()->get()->mapWithKeys(fn (BusGroup $b): array => [$b->id => $b->name.($b->capacity ? ' · '.$b->members()->count().'/'.$b->capacity : '')])->all())])
                        ->action(function (Collection $records, array $data, Buses $buses) use ($trip): void {
                            $bus = $trip->busGroups()->findOrFail($data['bus_group_id']);
                            $result = $buses->assign($bus, $records->pluck('id')->all());
                            Notification::make()->success()->title($result['assigned'].' put on '.$bus->name)->body(implode(' ', array_merge($result['warnings'], $result['skipped'])) ?: null)->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('unbus')
                        ->label('Take off their bus')
                        ->icon('heroicon-o-minus-circle')
                        ->color('gray')
                        ->authorize($this->may('bus.assign'))
                        ->requiresConfirmation()
                        ->action(function (Collection $records, Buses $buses): void {
                            Notification::make()->success()->title($buses->unassign($records->pluck('id')->all()).' taken off')->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('room')
                        ->label('Put in a room')
                        ->icon('heroicon-o-home')
                        ->authorize($this->may('room.assign'))
                        ->schema([Select::make('room_id')->label('Room')->required()->native(false)->searchable()
                            ->options(fn (): array => $trip->rooms()->with(['hotel', 'assignments'])->get()->mapWithKeys(fn (Room $r): array => [$r->id => $r->hotel->cityLabel().' · '.$r->describe().' · '.$r->bedsFree().' free'])->all())])
                        ->action(function (Collection $records, array $data, Rooming $rooming) use ($trip): void {
                            $room = $trip->rooms()->findOrFail($data['room_id']);
                            $result = $rooming->assign($room, $records->pluck('id')->all());
                            $problems = array_filter($rooming->problemsWith($room->hotel->fresh()), fn (array $p): bool => $p['room'] === $room->label);
                            Notification::make()->{$problems === [] ? 'success' : 'warning'}()->title($result['assigned'].' put in room '.$room->label)->body(implode(' ', array_merge(array_column($problems, 'detail'), $result['skipped'])) ?: null)->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('visa')
                        ->label('Mark visa')
                        ->icon('heroicon-o-identification')
                        ->authorize($this->may('visa.update'))
                        ->schema([
                            Select::make('visa_status')->label('Status')->options(BookingPilgrim::VISA_STATUSES)->required()->native(false),
                            TextInput::make('visa_agent')->label('Agent')->maxLength(80),
                            DatePicker::make('on')->label('Date')->native(false)->default(today()),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            foreach ($records as $bp) {
                                $bp->forceFill(array_filter([
                                    'visa_status' => $data['visa_status'],
                                    'visa_agent' => $data['visa_agent'] ?? null,
                                    'visa_submitted_on' => $data['visa_status'] === BookingPilgrim::VISA_SUBMITTED ? ($data['on'] ?? today()) : null,
                                    'visa_issued_on' => $data['visa_status'] === BookingPilgrim::VISA_ISSUED ? ($data['on'] ?? today()) : null,
                                ], fn ($v) => $v !== null))->save();
                            }

                            Notification::make()->success()->title($records->count().' marked '.strtolower(BookingPilgrim::VISA_STATUSES[$data['visa_status']]))->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('excursion')
                        ->label('Add to an additional trip')
                        ->icon('heroicon-o-map')
                        ->authorize($this->may('excursionBooking.create'))
                        ->visible(fn (): bool => $trip->excursions()->exists())
                        ->schema([Select::make('trip_excursion_id')->label('Which trip')->required()->native(false)->options(fn (): array => $trip->excursions->mapWithKeys(fn (TripExcursion $x): array => [$x->id => $x->title.' · '.$x->priceLabel()])->all())])
                        ->action(function (Collection $records, array $data, Excursions $excursions) use ($trip): void {
                            $excursion = $trip->excursions()->findOrFail($data['trip_excursion_id']);
                            $n = 0;
                            $errors = [];

                            foreach ($records->groupBy('booking_id') as $bookingId => $group) {
                                try {
                                    $excursions->book($excursion, Booking::query()->findOrFail($bookingId), $group->pluck('id')->all());
                                    $n += $group->count();
                                } catch (\InvalidArgumentException $e) {
                                    $errors[] = $e->getMessage();
                                }
                            }

                            Notification::make()->{$errors === [] ? 'success' : 'warning'}()->title($n.' added to '.$excursion->title)->body(implode(' ', $errors) ?: null)->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('usd_list')
                        ->label('Put on the bank USD list')
                        ->icon('heroicon-o-banknotes')
                        ->authorize($this->may('usdList.update'))
                        ->schema([
                            MoneyInput::make('amount_minor', 'currency', 'USD each')->default(50000),
                            Hidden::make('currency')->default('USD'),
                            TextInput::make('note')->maxLength(160),
                        ])
                        ->action(function (Collection $records, array $data) use ($trip): void {
                            foreach ($records as $bp) {
                                $trip->usdAllocations()->updateOrCreate(['booking_pilgrim_id' => $bp->getKey()], ['amount_minor' => (int) $data['amount_minor'], 'note' => $data['note'] ?? null]);
                            }

                            Notification::make()->success()->title($records->count().' on the USD list at '.Money::ofMinor((int) $data['amount_minor'], 'USD')->format().' each')->body('Print it under Money → Reports → Bank USD list.')->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('usd_list_off')
                        ->label('Take off the USD list')
                        ->icon('heroicon-o-minus-circle')
                        ->color('gray')
                        ->authorize($this->may('usdList.update'))
                        ->action(function (Collection $records) use ($trip): void {
                            $n = $trip->usdAllocations()->whereIn('booking_pilgrim_id', $records->pluck('id'))->delete();
                            Notification::make()->success()->title($n.' taken off the USD list')->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('cards')
                        ->label('Print customer cards')
                        ->icon('heroicon-o-identification')
                        ->color('gray')
                        ->authorize($this->may('card.create'))
                        ->action(fn (Collection $records) => redirect(route('lists.cards', ['kind' => 'customer', 'ids' => $records->pluck('id')->implode(',')])))
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('tags')
                        ->label('Print luggage tags')
                        ->icon('heroicon-o-tag')
                        ->color('gray')
                        ->authorize($this->may('card.create'))
                        ->action(fn (Collection $records) => redirect(route('lists.cards', ['kind' => 'luggage', 'ids' => $records->pluck('id')->implode(',')])))
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('csv')
                        ->label('Export selection (CSV)')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->color('gray')
                        ->authorize($this->may('list.view'))
                        ->action(fn (Collection $records) => redirect(route('lists.manifest', ['trip' => $trip, 'preset' => 'office', 'format' => 'csv', 'ids' => $records->pluck('id')->implode(',')])))
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->emptyStateHeading('Nobody booked yet');
    }

    private function may(string $permission): \Closure
    {
        return fn (): bool => (bool) Filament::auth()->user()?->can($permission);
    }
}
