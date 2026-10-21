<?php

namespace App\Filament\Resources\Pilgrims\Tables;

use App\Models\Document;
use App\Models\Pilgrim;
use App\Support\BlindIndex;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PilgrimsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['customer', 'documents']))
            ->columns([
                TextColumn::make('name_en')->label('Name')
                    ->searchable(query: function (Builder $query, string $search): void {
                        $term = trim($search);

                        // An ID number is found through its blind index, never by scanning ciphertext.
                        if (preg_match('/^[Aa]\s?\d{6}$/', $term)) {
                            $query->where('id_number_hash', BlindIndex::of($term));

                            return;
                        }

                        $query->where(fn (Builder $query) => $query->where('name_en', 'like', "%{$term}%")->orWhere('phone', 'like', "%{$term}%")->orWhere('name_dv', 'like', "%{$term}%"));
                    })
                    ->description(fn (Pilgrim $record): string => trim(($record->idNumberMasked() ?? 'no ID number').' · '.($record->customer?->name ?? ''))),
                TextColumn::make('registration_status')->label('Status')->badge()
                    ->color(fn (string $state): string => $state === Pilgrim::VERIFIED ? 'success' : 'warning'),
                TextColumn::make('docs')->label('Copies')
                    ->state(fn (Pilgrim $record): string => collect(Document::REQUIRED_FOR_PILGRIMS)->map(function (string $type) use ($record): string {
                        $doc = $record->document($type);

                        return match ($doc?->status) {
                            Document::VERIFIED => '✓',
                            Document::PENDING => '…',
                            Document::REJECTED => '✗',
                            default => '–',
                        };
                    })->implode(' '))
                    ->tooltip('ID card · passport · photo')
                    ->visibleFrom('sm'),
                TextColumn::make('phone')->visibleFrom('md'),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('registration_status')->label('Status')->options([Pilgrim::REGISTERED => 'Registered', Pilgrim::VERIFIED => 'Verified']),
                Filter::make('missing_passport')->label('No passport copy')
                    ->query(fn (Builder $query) => $query->whereDoesntHave('documents', fn ($d) => $d->where('type', Document::PASSPORT))),
                Filter::make('mismatch')->label('ID and passport disagree')
                    ->query(fn (Builder $query) => $query->whereNotNull('identity_mismatches')->where('identity_mismatches', 'like', '%"explained":false%')),
            ])
            ->recordActions([EditAction::make()->label('Open')])
            ->emptyStateHeading('No pilgrims yet')
            ->emptyStateDescription('Register the first one with a name and a phone number.');
    }
}
