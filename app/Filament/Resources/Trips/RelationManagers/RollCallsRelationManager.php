<?php

namespace App\Filament\Resources\Trips\RelationManagers;

use App\Models\BusGroup;
use App\Models\RollCall;
use App\Models\RollCallMark;
use App\Models\Trip;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

/** The head counts taken on the ground, with who is still unaccounted for. */
class RollCallsRelationManager extends RelationManager
{
    protected static string $relationship = 'rollCalls';

    protected static ?string $title = 'Head counts';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return (bool) Filament::auth()->user()?->can('rollCall.viewAny') && $ownerRecord instanceof Trip && $ownerRecord->rollCalls()->exists() || (bool) Filament::auth()->user()?->can('rollCall.create') && $ownerRecord instanceof Trip && in_array($ownerRecord->status, [Trip::CLOSED, Trip::TRAVELLING], true);
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        /** @var Trip $trip */
        $trip = $this->getOwnerRecord();

        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['marks.bookingPilgrim.pilgrim', 'bus', 'taker']))
            ->columns([
                TextColumn::make('moment')
                    ->description(fn (RollCall $record): string => ($record->bus?->name ?? 'Whole trip').' · '.$record->taken_at->format('j M H:i').' · '.($record->taker?->name ?? '')),
                TextColumn::make('tally')->label('Count')
                    ->state(fn (RollCall $record): string => $record->countIn(RollCallMark::PRESENT).' here · '.$record->countIn(RollCallMark::ABSENT).' not · '.$record->countIn(RollCallMark::EXCUSED).' excused · '.$record->unmarked()->count().' uncounted'),
                TextColumn::make('missing')->label('Unaccounted for')->badge()
                    ->state(fn (RollCall $record): string => ($n = $record->unaccountedFor()->count()) === 0 ? 'All here' : $n.' missing')
                    ->color(fn (string $state): string => $state === 'All here' ? 'success' : 'danger'),
            ])
            ->defaultSort('taken_at', 'desc')
            ->headerActions([
                Action::make('start')
                    ->label('Start a count')
                    ->icon('heroicon-o-plus')
                    ->authorize(fn (): bool => (bool) Filament::auth()->user()?->can('rollCall.create'))
                    ->schema([
                        TextInput::make('moment')->required()->maxLength(80)->placeholder('Boarding at the hotel'),
                        Select::make('bus_group_id')->label('Bus')->native(false)->placeholder('Whole trip')->options(fn (): array => $trip->busGroups()->get()->mapWithKeys(fn (BusGroup $b): array => [$b->id => $b->name])->all()),
                    ])
                    ->action(function (array $data) use ($trip): void {
                        $r = $trip->rollCalls()->create(['moment' => $data['moment'], 'bus_group_id' => $data['bus_group_id'] ?? null]);
                        Notification::make()->success()->title('Count started')->body('Take it on the ground page: '.route('ground.count', [$trip, $r]))->send();
                    }),
            ])
            ->recordActions([
                Action::make('open')->label('Take it')->icon('heroicon-o-device-phone-mobile')->color('gray')
                    ->url(fn (RollCall $record): string => route('ground.count', [$trip, $record]), shouldOpenInNewTab: true),
                Action::make('who')->label('Who is missing')->icon('heroicon-o-magnifying-glass')->color('danger')
                    ->visible(fn (RollCall $record): bool => ! $record->isSettled())
                    ->modalHeading(fn (RollCall $record): string => $record->moment.' · unaccounted for')
                    ->modalContent(fn (RollCall $record) => new HtmlString('<ul class="list-disc pl-5">'.$record->unaccountedFor()->map(fn ($bp) => '<li>'.e($bp->pilgrim->name_en).' · '.e($bp->booking->customer->name).($bp->pilgrim->phone ? ' · <a class="underline" href="tel:'.e($bp->pilgrim->phone).'">'.e($bp->pilgrim->phone).'</a>' : '').'</li>')->implode('').'</ul>'))
                    ->modalSubmitAction(false)->modalCancelActionLabel('Close'),
            ])
            ->emptyStateHeading('No head counts yet');
    }
}
