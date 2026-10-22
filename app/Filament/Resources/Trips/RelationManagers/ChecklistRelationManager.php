<?php

namespace App\Filament\Resources\Trips\RelationManagers;

use App\Filament\Support\TripOptions;
use App\Models\Trip;
use App\Models\TripChecklistItem;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

class ChecklistRelationManager extends RelationManager
{
    protected static string $relationship = 'checklist';

    protected static ?string $title = 'Checklist';

    /** Editable on the view page too: who may do what is decided by the policy, not by the page. */
    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('title')->required()->maxLength(160)->columnSpanFull(),
            DatePicker::make('due_on')->label('Due')->native(false),
            Toggle::make('is_blocking')->label('Blocks departure')->helperText('The trip cannot leave until this is done.'),
            Textarea::make('notes')->rows(2)->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                IconColumn::make('done_at')->label('')->boolean()->state(fn (TripChecklistItem $record): bool => $record->isDone()),
                TextColumn::make('title')
                    ->description(fn (TripChecklistItem $record): string => $record->isDone()
                        ? 'Done '.$record->done_at->format('j M').($record->doneBy ? ' by '.$record->doneBy->name : '')
                        : ($record->due_on ? 'Due '.$record->due_on->format('j M').($record->isOverdue() ? ' · overdue' : '') : '')),
                IconColumn::make('is_blocking')->label('Blocks')->boolean()->visibleFrom('sm'),
            ])
            ->defaultSort('due_on')
            ->filters([
                TernaryFilter::make('done')->label('Done')->default(false)
                    ->queries(true: fn ($query) => $query->whereNotNull('done_at'), false: fn ($query) => $query->whereNull('done_at'), blank: fn ($query) => $query),
            ])
            ->headerActions([
                CreateAction::make()->label('Add item'),
                Action::make('copy_from')
                    ->label('Copy from another trip')
                    ->color('gray')
                    ->authorize('create', TripChecklistItem::class)
                    ->schema([
                        Select::make('from')->label('Copy the list from')->required()->searchable()
                            ->options(fn (): array => collect(TripOptions::all())->except($this->getOwnerRecord()->getKey())->all()),
                    ])
                    ->action(function (array $data): void {
                        /** @var Trip $to */
                        $to = $this->getOwnerRecord();
                        $from = Trip::query()->findOrFail($data['from']);
                        $shift = $from->departs_on && $to->departs_on ? (int) $from->departs_on->diffInDays($to->departs_on, false) : 0;

                        $count = DB::transaction(function () use ($from, $to, $shift): int {
                            $n = 0;
                            foreach ($from->checklist as $item) {
                                $to->checklist()->create([
                                    'title' => $item->title,
                                    'due_on' => $item->due_on?->copy()->addDays($shift),
                                    'is_blocking' => $item->is_blocking,
                                    'notes' => $item->notes,
                                    'sort_order' => $item->sort_order,
                                ]);
                                $n++;
                            }

                            return $n;
                        });

                        Notification::make()->success()->title($count.' items copied')->send();
                    }),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('done')
                        ->label('Done')
                        ->icon('heroicon-o-check')
                        ->color('success')
                        ->visible(fn (TripChecklistItem $record): bool => ! $record->isDone())
                        ->authorize('tick', TripChecklistItem::class)
                        ->action(function (TripChecklistItem $record): void {
                            /** @var User $user */
                            $user = Filament::auth()->user();
                            $record->markDone($user);
                        }),
                    Action::make('not_done')
                        ->label('Not done')
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->color('gray')
                        ->visible(fn (TripChecklistItem $record): bool => $record->isDone())
                        ->authorize('tick', TripChecklistItem::class)
                        ->action(fn (TripChecklistItem $record) => $record->markNotDone()),
                    EditAction::make(),
                    DeleteAction::make(),
                ]),
            ])
            ->emptyStateHeading('Nothing on the list');
    }
}
