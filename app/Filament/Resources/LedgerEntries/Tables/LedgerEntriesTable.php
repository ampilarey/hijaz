<?php

namespace App\Filament\Resources\LedgerEntries\Tables;

use App\Filament\Support\BookActions;
use App\Filament\Support\TripOptions;
use App\Models\Account;
use App\Models\LedgerCategory;
use App\Models\LedgerEntry;
use App\Services\Documents\DocumentWallet;
use App\Services\Finance\Books;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class LedgerEntriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['account', 'category', 'trip', 'documents.versions']))
            ->columns([
                Split::make([
                    Stack::make([
                        TextColumn::make('description')->searchable()->wrap()->weight('medium')
                            ->description(fn (LedgerEntry $record): string => implode(' · ', array_filter([$record->account->name, $record->category?->name ?? $record->kindLabel(), $record->trip?->code, $record->counterparty, $record->reference]))),
                        TextColumn::make('occurred_on')->label('Date')->date('j M Y')->sortable()->size('xs')->color('gray'),
                    ]),
                    TextColumn::make('amount_minor')->label('Amount')->alignEnd()->weight('medium')->grow(false)
                        ->state(fn (LedgerEntry $record): string => ($record->direction === LedgerEntry::IN ? '+' : '−').$record->money()->format())
                        ->color(fn (LedgerEntry $record): string => $record->isVoided() ? 'gray' : ($record->direction === LedgerEntry::IN ? 'success' : 'danger'))
                        ->description(fn (LedgerEntry $record): ?string => $record->isVoided() ? 'VOID: '.$record->void_reason : ($record->currency !== 'MVR' ? ($record->mvr_minor === null ? 'no MVR rate' : '≈ '.Money::ofMinor($record->mvr_minor, 'MVR')->format()) : null)),
                    TextColumn::make('kind')->badge()->grow(false)->state(fn (LedgerEntry $record): string => $record->kindLabel())->visibleFrom('md'),
                    TextColumn::make('recorder.name')->label('By')->grow(false)->visibleFrom('lg'),
                ])->from('md'),
            ])
            ->defaultSort('occurred_on', 'desc')
            ->filters([
                SelectFilter::make('account_id')->label('Account')->options(fn (): array => Account::query()->orderBy('sort_order')->pluck('name', 'id')->all()),
                SelectFilter::make('trip_id')->label('Trip')->options(fn (): array => TripOptions::all()),
                SelectFilter::make('category_id')->label('Category')->options(fn (): array => LedgerCategory::query()->orderBy('sort_order')->pluck('name', 'id')->all()),
                SelectFilter::make('kind')->options(LedgerEntry::KINDS),
                Filter::make('period')
                    ->schema([DatePicker::make('from')->native(false), DatePicker::make('to')->native(false)])
                    ->query(fn (Builder $query, array $data) => $query->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('occurred_on', '>=', $d))->when($data['to'] ?? null, fn ($q, $d) => $q->whereDate('occurred_on', '<=', $d))),
            ])
            ->headerActions(BookActions::all())
            ->recordActions([
                ActionGroup::make([
                    Action::make('evidence')->label('Evidence')->icon('heroicon-o-paper-clip')->color('gray')
                        ->visible(fn (LedgerEntry $record): bool => $record->evidence()?->currentVersion() !== null)
                        ->url(fn (LedgerEntry $record, DocumentWallet $wallet): string => $wallet->downloadUrl($record->evidence()->currentVersion()), shouldOpenInNewTab: true),
                    Action::make('void')->label('Void')->icon('heroicon-o-x-circle')->color('danger')
                        ->visible(fn (LedgerEntry $record): bool => ! $record->isVoided())
                        ->authorize(fn (): bool => (bool) Filament::auth()->user()?->can('ledger.void'))
                        ->requiresConfirmation()
                        ->modalDescription(fn (LedgerEntry $record): string => $record->group_key ? 'The other entries of this transfer, purchase or TT are voided with it. The rows stay, marked void.' : 'The row stays, marked void; enter the right one afresh.')
                        ->schema([Textarea::make('reason')->required()->rows(2)])
                        ->action(function (LedgerEntry $record, array $data, Books $books): void {
                            $books->void($record, $data['reason']);
                            Notification::make()->warning()->title('Voided')->send();
                        }),
                ]),
            ])
            ->emptyStateHeading('Nothing in the books yet')
            ->emptyStateDescription('Verified payments post themselves. Record expenses, transfers, currency purchases and TTs with the buttons above.');
    }
}
