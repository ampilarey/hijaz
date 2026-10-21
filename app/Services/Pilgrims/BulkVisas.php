<?php

namespace App\Services\Pilgrims;

use App\Models\Document;
use App\Models\DocumentBatch;
use App\Models\DocumentBatchItem;
use App\Models\DocumentExtraction;
use App\Models\Pilgrim;
use App\Models\Trip;
use App\Services\Documents\DocumentWallet;
use App\Services\Documents\FieldParser;
use App\Services\Documents\PdfText;
use App\Services\Documents\ReaderManager;
use App\Services\Documents\Readers\Extraction;
use App\Support\BlindIndex;
use App\Support\EncryptedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Visas arrive from the agent all at once. Each file (or each page of a
 * multi-page PDF) is read and matched to a pilgrim by passport number,
 * then by name. Nothing is attached until staff confirm the batch.
 */
class BulkVisas
{
    public function __construct(
        private readonly ReaderManager $readers,
        private readonly DocumentWallet $wallet,
    ) {}

    /**
     * @param  list<array{bytes: string, name: string, mime: string}>  $files
     */
    public function open(Trip $trip, array $files, string $type = Document::VISA): DocumentBatch
    {
        $batch = DocumentBatch::query()->create([
            'trip_id' => $trip->getKey(),
            'type' => $type,
            'uploaded_by' => Auth::id(),
        ]);

        $count = 0;

        foreach ($files as $file) {
            foreach ($this->pieces($file) as $piece) {
                $this->addItem($batch, $piece['bytes'], $piece['name'], $piece['mime'], $piece['page'], $type);
                $count++;
            }
        }

        $batch->forceFill(['file_count' => $count, 'matched_count' => $batch->items()->where('status', DocumentBatchItem::MATCHED)->count()])->save();

        return $batch;
    }

    /** @return list<array{bytes: string, name: string, mime: string, page: int|null}> */
    private function pieces(array $file): array
    {
        if ($file['mime'] !== 'application/pdf') {
            return [['bytes' => $file['bytes'], 'name' => $file['name'], 'mime' => $file['mime'], 'page' => null]];
        }

        $pages = PdfText::split($file['bytes']);

        if ($pages === null || count($pages) <= 1) {
            // Cannot split (compressed object streams) or single page: one item, read per page later.
            $texts = PdfText::pages($file['bytes']);

            if (count($texts) <= 1) {
                return [['bytes' => $file['bytes'], 'name' => $file['name'], 'mime' => $file['mime'], 'page' => null]];
            }

            // Whole file attached to each matched pilgrim, with the page noted.
            return array_map(fn (int $i): array => ['bytes' => $file['bytes'], 'name' => $file['name'], 'mime' => $file['mime'], 'page' => $i + 1], array_keys($texts));
        }

        return array_map(fn (string $bytes, int $i): array => [
            'bytes' => $bytes,
            'name' => preg_replace('/\.pdf$/i', '', $file['name']).'-p'.($i + 1).'.pdf',
            'mime' => 'application/pdf',
            'page' => $i + 1,
        ], $pages, array_keys($pages));
    }

    private function addItem(DocumentBatch $batch, string $bytes, string $name, string $mime, ?int $page, string $type): DocumentBatchItem
    {
        $path = 'batches/'.$batch->getKey().'/'.Str::random(40).'.'.(strtolower(pathinfo($name, PATHINFO_EXTENSION)) ?: 'bin');
        EncryptedFile::put($this->wallet->disk(), $path, $bytes);

        // Read the page that belongs to this item when the file could not be split.
        $readBytes = $bytes;
        $x = null;

        if ($mime === 'application/pdf' && $page !== null) {
            $texts = PdfText::pages($bytes);

            if (count($texts) > 1 && isset($texts[$page - 1])) {
                $x = new Extraction('pdf-text', rawText: $texts[$page - 1]);
                FieldParser::apply($x, $type);
            }
        }

        $x ??= $this->readers->run($this->readers->reader(), $readBytes, $mime, $type);

        [$pilgrim, $reason] = $this->match($batch->trip, $x->fields);

        return $batch->items()->create([
            'disk' => $this->wallet->disk(),
            'path' => $path,
            'original_filename' => mb_substr($name, 0, 255),
            'mime_type' => $mime,
            'page' => $page,
            'fields' => $x->fields ?: null,
            'matched_pilgrim_id' => $pilgrim?->getKey(),
            'match_reason' => $reason,
            'status' => $pilgrim ? DocumentBatchItem::MATCHED : DocumentBatchItem::UNMATCHED,
        ]);
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array{Pilgrim|null, string|null}
     */
    public function match(?Trip $trip, array $fields): array
    {
        if (filled($fields['passport_number'] ?? null)) {
            $pilgrim = Pilgrim::query()->where('passport_number_hash', BlindIndex::of($fields['passport_number']))->first();

            if ($pilgrim) {
                return [$pilgrim, 'passport_number'];
            }
        }

        if (filled($fields['name_en'] ?? null)) {
            $best = null;
            $bestScore = 0.0;

            foreach (Pilgrim::query()->orderByDesc('id')->limit(2000)->get(['id', 'name_en']) as $candidate) {
                $score = IdentityCheck::nameSimilarity($fields['name_en'], $candidate->name_en);

                if ($score > $bestScore) {
                    $bestScore = $score;
                    $best = $candidate;
                }
            }

            if ($best !== null && $bestScore >= 0.8) {
                return [Pilgrim::query()->find($best->id), 'name'];
            }
        }

        return [null, null];
    }

    public function assign(DocumentBatchItem $item, Pilgrim $pilgrim): void
    {
        $item->forceFill(['matched_pilgrim_id' => $pilgrim->getKey(), 'match_reason' => 'manual', 'status' => DocumentBatchItem::ASSIGNED])->save();
    }

    public function discard(DocumentBatchItem $item): void
    {
        $item->forceFill(['status' => DocumentBatchItem::DISCARDED])->save();
    }

    /** Attach every matched or assigned item to its pilgrim as a verified document. */
    public function confirm(DocumentBatch $batch, bool $verify = true): int
    {
        return DB::transaction(function () use ($batch, $verify): int {
            $attached = 0;

            foreach ($batch->items()->whereIn('status', [DocumentBatchItem::MATCHED, DocumentBatchItem::ASSIGNED])->get() as $item) {
                $pilgrim = $item->pilgrim;
                $bytes = $item->contents();

                if ($pilgrim === null || $bytes === null) {
                    continue;
                }

                $fields = $item->fields ?? [];
                $version = $this->wallet->store($pilgrim, $bytes, $item->original_filename, $item->mime_type, $batch->type, $batch->trip_id, array_filter([
                    'number' => $fields['number'] ?? null,
                    'issued_at' => $fields['issued_at'] ?? null,
                    'expires_at' => $fields['expires_at'] ?? null,
                    'notes' => $item->page ? 'Page '.$item->page.' of '.$item->original_filename : null,
                ]));

                $version->extractions()->create([
                    'reader' => 'batch',
                    'fields' => $fields ?: null,
                    'status' => DocumentExtraction::ACCEPTED,
                    'accepted_fields' => $fields ?: null,
                    'reviewed_by' => Auth::id(),
                    'reviewed_at' => now(),
                ]);

                if ($verify) {
                    $this->wallet->verify($version->document);
                }

                $item->forceFill(['status' => DocumentBatchItem::ATTACHED, 'document_id' => $version->document_id])->save();
                $attached++;
            }

            $batch->forceFill(['status' => DocumentBatch::CONFIRMED, 'confirmed_at' => now()])->save();

            return $attached;
        });
    }
}
