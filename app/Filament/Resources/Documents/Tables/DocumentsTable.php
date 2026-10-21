<?php

namespace App\Filament\Resources\Documents\Tables;

use App\Filament\Resources\Pilgrims\PilgrimResource;
use App\Filament\Support\DocumentActions;
use App\Models\Document;
use App\Models\Pilgrim;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DocumentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->forPilgrims()->with(['documentable', 'trip', 'versions.extractions']))
            ->columns([
                TextColumn::make('documentable.name_en')->label('Pilgrim')
                    ->description(fn (Document $record): string => $record->typeLabel().($record->trip ? ' · '.$record->trip->code : '')),
                TextColumn::make('status')->badge()
                    ->color(fn (string $state): string => match ($state) {
                        Document::VERIFIED => 'success', Document::REJECTED => 'danger', default => 'warning',
                    }),
                TextColumn::make('next')->label('Next')
                    ->state(fn (Document $record): string => $record->latestExtraction()?->isProposed() ? 'Accept reading' : ($record->status === Document::PENDING ? 'Verify' : '—'))
                    ->visibleFrom('sm'),
                TextColumn::make('updated_at')->label('Updated')->since()->visibleFrom('md'),
            ])
            ->defaultSort('updated_at', 'desc')
            ->filters([
                SelectFilter::make('type')->options(Document::TYPES),
                SelectFilter::make('status')->options([Document::PENDING => 'Pending', Document::VERIFIED => 'Verified', Document::REJECTED => 'Rejected']),
            ])
            ->recordActions([
                ActionGroup::make([
                    ...DocumentActions::forRecord(),
                    Action::make('pilgrim')->label('Pilgrim')->icon('heroicon-o-user')->color('gray')
                        ->url(fn (Document $record): ?string => $record->documentable instanceof Pilgrim ? PilgrimResource::getUrl('edit', ['record' => $record->documentable]) : null),
                ]),
            ])
            ->emptyStateHeading('Nothing to check');
    }
}
