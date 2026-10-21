<?php

namespace App\Services\Pilgrims;

use App\Models\Document;
use App\Models\DocumentExtraction;
use App\Models\Pilgrim;
use App\Services\Documents\DocumentWallet;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Staff looked at the scan beside the proposed fields and said yes. Only
 * now do the fields reach the pilgrim's record; what was actually saved is
 * written on the extraction, which is how a later cross-check knows what
 * each document said.
 */
class Acceptor
{
    /** Pilgrim columns each document type may fill. Nothing else is written from a reading. */
    public const PILGRIM_FIELDS = [
        Document::ID_CARD => ['name_en', 'name_dv', 'id_number', 'gender', 'date_of_birth', 'id_expires_on', 'address', 'island_id'],
        Document::PASSPORT => ['name_en', 'passport_number', 'gender', 'date_of_birth', 'passport_issued_on', 'passport_expires_on', 'passport_issuing_country', 'nationality'],
        Document::VISA => [],
        Document::VACCINATION => [],
    ];

    /** Document columns each type may fill. */
    public const DOCUMENT_FIELDS = [
        Document::ID_CARD => ['expires_at' => 'id_expires_on', 'number' => 'id_number'],
        Document::PASSPORT => ['expires_at' => 'passport_expires_on', 'issued_at' => 'passport_issued_on', 'number' => 'passport_number'],
        Document::VISA => ['expires_at' => 'expires_at', 'issued_at' => 'issued_at', 'number' => 'number'],
        Document::VACCINATION => ['expires_at' => 'expires_at', 'issued_at' => 'issued_at', 'number' => 'number'],
    ];

    public function __construct(
        private readonly DocumentWallet $wallet,
        private readonly IdentityCheck $identity,
    ) {}

    /**
     * @param  array<string, mixed>  $fields  The values as corrected on screen.
     */
    public function accept(DocumentExtraction $extraction, array $fields, bool $verify = false): void
    {
        $document = $extraction->version->document;
        $type = $document->type;
        $fields = array_filter($fields, fn ($v): bool => $v !== null && $v !== '');

        DB::transaction(function () use ($extraction, $document, $type, $fields, $verify): void {
            $pilgrim = $document->documentable;

            if ($pilgrim instanceof Pilgrim) {
                $allowed = self::PILGRIM_FIELDS[$type] ?? [];
                $pilgrim->fill(array_intersect_key($fields, array_flip($allowed)));

                if (isset($fields['nationality'])) {
                    $pilgrim->nationality = strtoupper(substr((string) $fields['nationality'], 0, 3));
                }

                $pilgrim->save();
            }

            foreach (self::DOCUMENT_FIELDS[$type] ?? [] as $column => $source) {
                if (isset($fields[$source])) {
                    $document->{$column} = $column === 'number' && $type !== Document::VISA && $type !== Document::VACCINATION
                        ? null // ID and passport numbers live encrypted on the pilgrim, not in the clear on the document
                        : $fields[$source];
                }
            }

            $document->save();

            $extraction->forceFill([
                'status' => DocumentExtraction::ACCEPTED,
                'reviewed_by' => Auth::id(),
                'reviewed_at' => now(),
                'accepted_fields' => $fields,
            ])->save();

            if ($verify) {
                $this->wallet->verify($document);
            }

            if ($pilgrim instanceof Pilgrim) {
                $this->identity->run($pilgrim->fresh());
            }
        });
    }

    public function reject(DocumentExtraction $extraction): void
    {
        $extraction->forceFill([
            'status' => DocumentExtraction::REJECTED,
            'reviewed_by' => Auth::id(),
            'reviewed_at' => now(),
        ])->save();
    }
}
