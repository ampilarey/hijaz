<?php

namespace App\Filament\Resources\Pilgrims\Tables;

use App\Models\Document;
use App\Models\Pilgrim;
use App\Support\BlindIndex;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\Layout\Split;
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
                Split::make([
                    TextColumn::make('name_en')->label('Name')->weight('medium')
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
                    Split::make([
                        TextColumn::make('registration_status')->label('Status')->badge()->grow(false)
                            ->color(fn (string $state): string => $state === Pilgrim::VERIFIED ? 'success' : 'warning'),
                        TextColumn::make('docs')->label('Copies')->alignEnd()->prefix('copies ')->color('gray')
                            ->state(fn (Pilgrim $record): string => collect(Document::REQUIRED_FOR_PILGRIMS)->map(function (string $type) use ($record): string {
                                $doc = $record->document($type);

                                return match ($doc?->status) {
                                    Document::VERIFIED => '✓',
                                    Document::PENDING => '…',
                                    Document::REJECTED => '✗',
                                    default => '–',
                                };
                            })->implode(' '))
                            ->tooltip('ID card · passport · photo'),
                    ])->grow(false),
                    TextColumn::make('phone')->grow(false)->visibleFrom('md'),
                ])->from('md'),
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
