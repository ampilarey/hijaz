<?php

namespace App\Filament\Resources\LedgerEntries\Pages;

use App\Filament\Resources\LedgerEntries\LedgerEntryResource;
use App\Models\Account;
use App\Models\LedgerEntry;
use App\Services\Finance\Books;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListLedgerEntries extends ListRecords
{
    protected static string $resource = LedgerEntryResource::class;

    public function getSubheading(): ?string
    {
        $books = app(Books::class);

        // Only accounts holding something: a phone screen has no room for a row of zeros.
        return Account::query()->where('is_active', true)->orderBy('sort_order')->get()
            ->map(fn (Account $a): array => [$a, $books->balance($a)])
            ->filter(fn (array $pair): bool => $pair[1]->minor !== 0)
            ->map(fn (array $pair): string => $pair[0]->name.': '.$pair[1]->format())
            ->implode(' · ') ?: null;
    }

    public function getTabs(): array
    {
        return [
            'live' => Tab::make('All')->modifyQueryUsing(fn (Builder $query) => $query->live()),
            'income' => Tab::make('Income')->modifyQueryUsing(fn (Builder $query) => $query->live()->where('kind', LedgerEntry::INCOME)),
            'expense' => Tab::make('Expenses')->modifyQueryUsing(fn (Builder $query) => $query->live()->where('kind', LedgerEntry::EXPENSE)),
            'moves' => Tab::make('Transfers and purchases')->modifyQueryUsing(fn (Builder $query) => $query->live()->whereIn('kind', [LedgerEntry::TRANSFER, LedgerEntry::EXCHANGE])),
            'void' => Tab::make('Voided')->modifyQueryUsing(fn (Builder $query) => $query->whereNotNull('voided_at')),
        ];
    }
}
