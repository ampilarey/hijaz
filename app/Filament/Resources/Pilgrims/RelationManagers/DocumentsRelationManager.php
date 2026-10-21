<?php

namespace App\Filament\Resources\Pilgrims\RelationManagers;

use App\Filament\Support\DocumentActions;
use App\Models\Document;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'documents';

    protected static ?string $title = 'Copies';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('type')->label('Document')
                    ->state(fn (Document $record): string => $record->typeLabel().($record->trip ? ' · '.$record->trip->code : ''))
                    ->description(fn (Document $record): string => self::describe($record)),
                TextColumn::make('status')->badge()
                    ->color(fn (string $state): string => match ($state) {
                        Document::VERIFIED => 'success', Document::REJECTED => 'danger', default => 'warning',
                    }),
                TextColumn::make('expires_at')->label('Expires')->date('j M Y')->visibleFrom('sm'),
            ])
            ->headerActions([DocumentActions::upload(fn () => $this->getOwnerRecord())])
            ->recordActions(DocumentActions::forRecord())
            ->emptyStateHeading('No copies yet')
            ->emptyStateDescription('Upload the ID card, passport and photo when you have them.');
    }

    private static function describe(Document $record): string
    {
        $x = $record->latestExtraction();
        $version = $record->currentVersion();

        if ($version === null) {
            return 'no file';
        }

        $parts = ['v'.$version->version.' · '.$version->created_at->format('j M')];

        if ($x?->isProposed()) {
            $parts[] = count($x->fields ?? []).' fields to accept';
        } elseif ($record->status === Document::REJECTED && $record->rejection_reason) {
            $parts[] = $record->rejection_reason;
        }

        return implode(' · ', $parts);
    }
}
