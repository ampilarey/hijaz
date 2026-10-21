<?php

namespace App\Filament\Resources\Trips\RelationManagers;

use App\Models\BookingPilgrim;
use App\Models\BusGroup;
use App\Models\Trip;
use App\Models\User;
use App\Services\Operations\Buses;
use App\Support\Access;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class BusesRelationManager extends RelationManager
{
    protected static string $relationship = 'busGroups';

    protected static ?string $title = 'Buses';

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
        return $schema->columns(2)->components([
            TextInput::make('name')->required()->maxLength(40)->placeholder('Bus 1'),
            TextInput::make('capacity')->label('Seats')->integer()->minValue(1)->maxValue(99),
            Select::make('leader_user_id')->label('Leader (staff)')->native(false)->searchable()
                ->options(fn (): array => User::query()->where('is_active', true)->role([Access::GROUP_LEADER, Access::OPERATIONS, Access::ADMIN])->orderBy('name')->pluck('name', 'id')->all()),
            TextInput::make('leader_name')->label('Leader (if not staff)')->maxLength(80),
            TextInput::make('leader_phone')->label('Leader phone')->tel()->maxLength(40),
            ColorPicker::make('colour')->label('Colour on tags'),
            Textarea::make('notes')->rows(2)->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        /** @var Trip $trip */
        $trip = $this->getOwnerRecord();

        return $table
            ->columns([
                TextColumn::make('name')
                    ->description(fn (BusGroup $record): string => trim(($record->leaderLabel() ?? 'no leader').($record->leader_phone ? ' · '.$record->leader_phone : ''))),
                TextColumn::make('members_count')->label('On board')->counts('members')
                    ->state(fn (BusGroup $record): string => $record->members()->count().($record->capacity ? ' of '.$record->capacity : ''))
                    ->color(fn (BusGroup $record): ?string => $record->isOverfull() ? 'danger' : null),
                TextColumn::make('checked_in')->label('Checked in')->state(fn (BusGroup $record): string => (string) $record->members()->whereNotNull('checked_in_at')->count())->visibleFrom('sm'),
            ])
            ->defaultSort('sort_order')
            ->headerActions([
                CreateAction::make()->label('Add a bus')->authorize($this->may('bus.create')),
            ])
            ->recordActions([
                Action::make('assign')
                    ->label('Add people')
                    ->icon('heroicon-o-user-plus')
                    ->authorize($this->may('bus.assign'))
                    ->modalWidth('2xl')
                    ->schema([
                        CheckboxList::make('ids')->label('Not yet on this bus')->required()->searchable()->bulkToggleable()
                            ->options(fn (BusGroup $record): array => $trip->travellers()->with(['pilgrim', 'booking.customer', 'busGroup'])->get()
                                ->reject(fn (BookingPilgrim $bp): bool => $bp->bus_group_id === $record->getKey())
                                ->sortBy(fn (BookingPilgrim $bp) => $bp->booking->customer->name.' '.$bp->pilgrim->name_en)
                                ->mapWithKeys(fn (BookingPilgrim $bp): array => [$bp->id => $bp->pilgrim->name_en.' · '.$bp->booking->customer->name.($bp->busGroup ? ' · now on '.$bp->busGroup->name : '')])->all()),
                    ])
                    ->action(function (BusGroup $record, array $data, Buses $buses): void {
                        $result = $buses->assign($record, array_map('intval', $data['ids']));
                        Notification::make()->success()->title($result['assigned'].' added to '.$record->name)->body(implode(' ', $result['warnings']) ?: null)->send();
                    }),
                Action::make('list')->label('Bus list')->icon('heroicon-o-document-text')->color('gray')
                    ->authorize($this->may('list.view'))
                    ->url(fn (BusGroup $record): string => route('lists.bus', $record), shouldOpenInNewTab: true),
                Action::make('tags')->label('Tags')->icon('heroicon-o-tag')->color('gray')
                    ->authorize($this->may('card.create'))
                    ->visible(fn (BusGroup $record): bool => $record->members()->exists())
                    ->url(fn (BusGroup $record): string => route('lists.cards', ['kind' => 'luggage', 'bus' => $record->getKey()]), shouldOpenInNewTab: true),
                EditAction::make()->authorize($this->may('bus.update')),
                DeleteAction::make()->authorize($this->may('bus.delete')),
            ])
            ->emptyStateHeading('No buses yet')
            ->emptyStateDescription('Add Bus 1, Bus 2… with a leader each, then put people on them here or from the Travellers tab.');
    }

    private function may(string $permission): \Closure
    {
        return fn (): bool => (bool) Filament::auth()->user()?->can($permission);
    }
}
