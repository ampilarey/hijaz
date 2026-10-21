<?php

namespace App\Filament\Resources\DocumentBatches\Pages;

use App\Filament\Resources\DocumentBatches\DocumentBatchResource;
use App\Models\Document;
use App\Models\DocumentBatch;
use App\Services\Pilgrims\BulkVisas;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;

class ViewDocumentBatch extends ViewRecord
{
    protected static string $resource = DocumentBatchResource::class;

    public function infolist(Schema $schema): Schema
    {
        /** @var DocumentBatch $batch */
        $batch = $this->getRecord();

        return $schema->components([
            Section::make()->schema([
                Text::make(fn (): string => ($batch->trip?->name ?? 'No trip').' · '.ucfirst($batch->type).' · '.$batch->file_count.' files, '.$batch->items()->whereIn('status', ['matched', 'assigned'])->count().' with a pilgrim · '.$batch->status),
            ]),
        ]);
    }

    protected function getHeaderActions(): array
    {
        /** @var DocumentBatch $batch */
        $batch = $this->getRecord();

        return [
            Action::make('confirm')
                ->label('Confirm and attach')
                ->icon('heroicon-o-check')
                ->color('success')
                ->visible(fn (): bool => $batch->status === DocumentBatch::REVIEW)
                ->authorize('create', Document::class)
                ->requiresConfirmation()
                ->modalDescription('Every matched or assigned file is attached to its pilgrim. Unmatched files are left behind.')
                ->schema([
                    Toggle::make('verify')->label('Mark them verified')->default(true)
                        ->visible(fn (): bool => (bool) Filament::auth()->user()?->can('document.verify')),
                ])
                ->action(function (array $data, BulkVisas $visas) use ($batch): void {
                    $n = $visas->confirm($batch, (bool) ($data['verify'] ?? false) && Filament::auth()->user()?->can('document.verify'));
                    Notification::make()->success()->title("{$n} attached")->send();
                }),
            Action::make('discard')
                ->label('Discard')
                ->color('gray')
                ->visible(fn (): bool => $batch->status === DocumentBatch::REVIEW)
                ->requiresConfirmation()
                ->action(function () use ($batch): void {
                    $batch->forceFill(['status' => DocumentBatch::DISCARDED])->save();
                    Notification::make()->title('Discarded')->send();
                }),
        ];
    }
}
