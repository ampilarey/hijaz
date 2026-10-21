<?php

namespace App\Filament\Resources\DocumentBatches\Tables;

use App\Models\DocumentBatch;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DocumentBatchesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('trip.name')->label('Trip')
                    ->description(fn (DocumentBatch $record): string => ucfirst($record->type).' · '.$record->created_at->format('j M, H:i')),
                TextColumn::make('matched_count')->label('Matched')
                    ->state(fn (DocumentBatch $record): string => $record->matched_count.' of '.$record->file_count),
                TextColumn::make('status')->badge()
                    ->color(fn (string $state): string => match ($state) {
                        DocumentBatch::CONFIRMED => 'success', DocumentBatch::DISCARDED => 'gray', default => 'warning'
                    }),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([ViewAction::make()->label('Review')])
            ->emptyStateHeading('No uploads yet')
            ->emptyStateDescription('Upload the visas for a trip as a folder of files or one PDF; they are matched to pilgrims by passport number.');
    }
}
