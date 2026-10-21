<?php

namespace App\Filament\Resources\DocumentBatches\Pages;

use App\Filament\Resources\DocumentBatches\DocumentBatchResource;
use App\Filament\Support\TripOptions;
use App\Models\Document;
use App\Models\Trip;
use App\Services\Pilgrims\BulkVisas;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class ListDocumentBatches extends ListRecords
{
    protected static string $resource = DocumentBatchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('upload')
                ->label('Upload visas')
                ->icon('heroicon-o-arrow-up-tray')
                ->authorize('create', Document::class)
                ->schema([
                    Select::make('trip_id')->label('Trip')->required()->options(fn (): array => TripOptions::all(liveOnly: true))->native(false),
                    Select::make('type')->options([Document::VISA => 'Visas', Document::VACCINATION => 'Vaccination certificates', Document::OTHER => 'Other'])->default(Document::VISA)->required()->native(false),
                    FileUpload::make('files')->label('Files or one multi-page PDF')->multiple()->required()
                        ->acceptedFileTypes(config('documents.mime_types'))
                        ->maxSize((int) config('documents.max_kilobytes'))
                        ->maxFiles(60)
                        ->storeFiles(false),
                ])
                ->action(function (array $data, BulkVisas $visas): void {
                    $files = [];

                    foreach ((array) $data['files'] as $file) {
                        if ($file instanceof TemporaryUploadedFile) {
                            $files[] = ['bytes' => (string) file_get_contents($file->getRealPath()), 'name' => $file->getClientOriginalName(), 'mime' => $file->getMimeType()];
                        }
                    }

                    $batch = $visas->open(Trip::query()->findOrFail($data['trip_id']), $files, $data['type']);

                    Notification::make()->success()->title("{$batch->matched_count} of {$batch->file_count} matched")->body('Review the list, assign the rest, then confirm.')->send();
                    $this->redirect(DocumentBatchResource::getUrl('view', ['record' => $batch]));
                }),
        ];
    }
}
