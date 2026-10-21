<?php

namespace App\Filament\Resources\DocumentBatches\RelationManagers;

use App\Models\DocumentBatch;
use App\Models\DocumentBatchItem;
use App\Models\Pilgrim;
use App\Services\Pilgrims\BulkVisas;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Files';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('original_filename')->label('File')
                    ->description(fn (DocumentBatchItem $record): string => trim(implode(' · ', array_filter([
                        $record->page ? 'page '.$record->page : null,
                        isset($record->fields['name_en']) ? $record->fields['name_en'] : null,
                        isset($record->fields['passport_number']) ? 'passport '.$record->fields['passport_number'] : null,
                        isset($record->fields['number']) ? 'visa '.$record->fields['number'] : null,
                    ])))),
                TextColumn::make('pilgrim.name_en')->label('Pilgrim')
                    ->placeholder('nobody')
                    ->description(fn (DocumentBatchItem $record): string => match ($record->match_reason) {
                        'passport_number' => 'matched by passport number',
                        'name' => 'matched by name, check it',
                        'manual' => 'assigned by hand',
                        default => '',
                    }),
                TextColumn::make('status')->badge()
                    ->color(fn (string $state): string => match ($state) {
                        DocumentBatchItem::MATCHED, DocumentBatchItem::ATTACHED => 'success',
                        DocumentBatchItem::ASSIGNED => 'info',
                        DocumentBatchItem::DISCARDED => 'gray',
                        default => 'warning',
                    }),
            ])
            ->recordActions([
                Action::make('assign')
                    ->label('Assign')
                    ->icon('heroicon-o-user-plus')
                    ->visible(fn (DocumentBatchItem $record): bool => $this->batch()->status === DocumentBatch::REVIEW && $record->status !== DocumentBatchItem::ATTACHED)
                    ->schema([
                        Select::make('pilgrim_id')->label('Pilgrim')->required()->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => Pilgrim::query()->where('name_en', 'like', "%{$search}%")->limit(20)->pluck('name_en', 'id')->all())
                            ->getOptionLabelUsing(fn ($value): ?string => Pilgrim::query()->find($value)?->name_en),
                    ])
                    ->action(function (DocumentBatchItem $record, array $data, BulkVisas $visas): void {
                        $visas->assign($record, Pilgrim::query()->findOrFail($data['pilgrim_id']));
                    }),
                Action::make('discard')
                    ->label('Discard')
                    ->icon('heroicon-o-trash')
                    ->color('gray')
                    ->visible(fn (DocumentBatchItem $record): bool => $this->batch()->status === DocumentBatch::REVIEW && ! in_array($record->status, [DocumentBatchItem::ATTACHED, DocumentBatchItem::DISCARDED], true))
                    ->action(fn (DocumentBatchItem $record, BulkVisas $visas) => $visas->discard($record)),
            ])
            ->emptyStateHeading('No files in this upload');
    }

    private function batch(): DocumentBatch
    {
        /** @var DocumentBatch $batch */
        $batch = $this->getOwnerRecord();

        return $batch;
    }
}
