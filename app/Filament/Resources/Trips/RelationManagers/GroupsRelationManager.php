<?php

namespace App\Filament\Resources\Trips\RelationManagers;

use App\Models\BookingPilgrim;
use App\Models\BusGroup;
use App\Models\Trip;
use App\Models\TripFlight;
use App\Models\TripGroup;
use App\Services\Operations\Groups;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

/**
 * The flight groups of a trip: the people who fly together, out and home,
 * and the buses that carry them on the ground.
 */
class GroupsRelationManager extends RelationManager
{
    protected static string $relationship = 'groups';

    protected static ?string $title = 'Groups';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return (bool) Filament::auth()->user()?->can('bus.viewAny');
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
            TextInput::make('name')->required()->maxLength(40)->placeholder('Group A')->columnSpanFull(),
            Select::make('outbound_flight_id')->label('Flight out')->native(false)
                ->options(fn (): array => self::flights($trip, TripFlight::OUTBOUND))
                ->helperText('Add the flights under Flights first; each one needs its date.'),
            Select::make('return_flight_id')->label('Flight home')->native(false)
                ->options(fn (): array => self::flights($trip, TripFlight::RETURN)),
            Textarea::make('notes')->rows(2)->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        /** @var Trip $trip */
        $trip = $this->getOwnerRecord();

        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['outboundFlight', 'returnFlight', 'buses'])->withCount('members'))
            ->columns([
                Split::make([
                    TextColumn::make('name')->weight('medium')
                        ->description(fn (TripGroup $record): string => $record->flightsLabel()),
                    TextColumn::make('members_count')->label('People')->grow(false)
                        ->state(fn (TripGroup $record): string => $record->members_count.' '.str('person')->plural($record->members_count)),
                    TextColumn::make('buses')->label('Buses')->grow(false)->html()
                        ->state(fn (TripGroup $record): HtmlString => new HtmlString($record->buses->isEmpty() ? '<span class="fi-color-gray">no bus yet</span>' : $record->buses->map(fn (BusGroup $b): string => '<span style="display:inline-flex;align-items:center;gap:.25rem;margin-right:.5rem;"><span style="width:.75rem;height:.75rem;border-radius:9999px;background:'.e($b->colour ?: '#a8a29e').'"></span>'.e($b->name).' '.$b->members()->count().($b->capacity ? '/'.$b->capacity : '').'</span>')->implode(''))),
                ])->from('md'),
            ])
            ->defaultSort('sort_order')
            ->headerActions([
                CreateAction::make()->label('Add a group')->icon('heroicon-o-plus')->authorize($this->may('bus.create')),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('assign')
                        ->label('Add people')
                        ->icon('heroicon-o-user-plus')
                        ->authorize($this->may('bus.assign'))
                        ->modalWidth('2xl')
                        ->schema([
                            CheckboxList::make('ids')->label('Not yet in this group')->required()->searchable()->bulkToggleable()
                                ->options(fn (TripGroup $record): array => $trip->travellers()->with(['pilgrim', 'booking.customer', 'group'])->get()
                                    ->reject(fn (BookingPilgrim $bp): bool => $bp->trip_group_id === $record->getKey())
                                    ->sortBy(fn (BookingPilgrim $bp) => $bp->booking->customer->name.' '.$bp->pilgrim->name_en)
                                    ->mapWithKeys(fn (BookingPilgrim $bp): array => [$bp->id => $bp->pilgrim->name_en.' · '.$bp->booking->customer->name.($bp->group ? ' · now in '.$bp->group->name : '')])->all()),
                        ])
                        ->action(function (TripGroup $record, array $data, Groups $groups): void {
                            $result = $groups->assign($record, array_map('intval', $data['ids']));
                            Notification::make()->success()->title($result['assigned'].' added to '.$record->name)
                                ->body(trim(($result['offBus'] > 0 ? $result['offBus'].' came off a bus of their old group. ' : '').implode(' ', $result['skipped'])) ?: null)->send();
                        }),
                    Action::make('airline')->label('Airline list')->icon('heroicon-o-document-text')->color('gray')
                        ->authorize($this->may('list.view'))
                        ->url(fn (TripGroup $record): string => route('lists.manifest', ['trip' => $trip, 'preset' => 'airline', 'group' => $record->getKey()]), shouldOpenInNewTab: true),
                    EditAction::make()->authorize($this->may('bus.update')),
                    DeleteAction::make()->authorize($this->may('bus.delete'))
                        ->modalDescription('The people and buses stay on the trip, out of any group.'),
                ]),
            ])
            ->emptyStateHeading('No groups yet')
            ->emptyStateDescription('A group is the people who fly together. Add the flights first, then a group for each flight, then its buses under Buses.');
    }

    /** @return array<int, string> */
    public static function flights(Trip $trip, string $direction): array
    {
        return $trip->flights()->where('direction', $direction)->orderBy('departs_at')->get()
            ->mapWithKeys(fn (TripFlight $f): array => [$f->id => $f->describe()])->all();
    }

    private function may(string $permission): \Closure
    {
        return fn (): bool => (bool) Filament::auth()->user()?->can($permission);
    }
}
