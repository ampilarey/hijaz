<?php

namespace App\Filament\Resources\Pilgrims\Pages;

use App\Filament\Resources\Pilgrims\PilgrimResource;
use App\Models\Customer;
use App\Models\Document;
use App\Models\DocumentExtraction;
use App\Models\Pilgrim;
use App\Services\Documents\DocumentWallet;
use App\Services\Pilgrims\Acceptor;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class CreatePilgrim extends CreateRecord
{
    protected static string $resource = PilgrimResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }

    /**
     * The scan, if there was one, is saved with the record as its ID card,
     * and what was read is recorded as accepted with exactly the values the
     * person checked and saved.
     */
    protected function handleRecordCreation(array $data): Model
    {
        /** @var Pilgrim $pilgrim */
        $pilgrim = parent::handleRecordCreation($data);

        $customer = $pilgrim->customer;

        if ($customer instanceof Customer && $customer->head_pilgrim_id === null && $pilgrim->relationship_to_head === 'self') {
            $customer->forceFill(['head_pilgrim_id' => $pilgrim->getKey()])->save();
        }

        $scan = $this->data['id_card_scan'] ?? null;
        $scan = is_array($scan) ? reset($scan) : $scan;

        if ($scan instanceof TemporaryUploadedFile) {
            $version = app(DocumentWallet::class)->store(
                $pilgrim,
                (string) file_get_contents($scan->getRealPath()),
                $scan->getClientOriginalName(),
                $scan->getMimeType(),
                Document::ID_CARD,
                null,
                array_filter(['expires_at' => $data['id_expires_on'] ?? null]),
            );

            $reading = json_decode((string) ($this->data['scan_reading'] ?? ''), true) ?: [];
            $accepted = array_filter(array_intersect_key($data, array_flip(Acceptor::PILGRIM_FIELDS[Document::ID_CARD])), fn ($v): bool => $v !== null && $v !== '');

            $version->extractions()->create([
                'reader' => $reading['reader'] ?? 'manual',
                'model' => $reading['model'] ?? null,
                'fields' => $reading['fields'] ?? null,
                'confidence' => $reading['confidence'] ?? null,
                'raw_text' => isset($reading['raw_text']) ? mb_substr((string) $reading['raw_text'], 0, 20000) : null,
                'error' => $reading['error'] ?? null,
                'status' => DocumentExtraction::ACCEPTED,
                'accepted_fields' => $accepted ?: null,
                'reviewed_by' => Filament::auth()->id(),
                'reviewed_at' => now(),
            ]);
        }

        return $pilgrim;
    }
}
