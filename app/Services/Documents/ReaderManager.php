<?php

namespace App\Services\Documents;

use App\Models\DocumentExtraction;
use App\Models\DocumentVersion;
use App\Services\Documents\Readers\AnthropicVisionReader;
use App\Services\Documents\Readers\Extraction;
use App\Services\Documents\Readers\ManualReader;
use App\Services\Documents\Readers\Reader;
use App\Services\Documents\Readers\TesseractReader;
use Illuminate\Support\Facades\Log;

/**
 * Picks the configured reader, runs it, and records what it proposed. A
 * reader that is missing or fails produces an empty proposal with the
 * error written down, never an exception on the upload screen.
 */
class ReaderManager
{
    public function reader(): Reader
    {
        $reader = match ((string) config('documents.reader', 'tesseract')) {
            'anthropic' => new AnthropicVisionReader,
            'manual' => new ManualReader,
            default => new TesseractReader,
        };

        return $reader->isAvailable() ? $reader : new ManualReader;
    }

    public function read(DocumentVersion $version, ?string $typeOverride = null): DocumentExtraction
    {
        $type = $typeOverride ?? $version->document->type;
        $bytes = $version->contents();
        $reader = $this->reader();

        if ($bytes === null) {
            $x = Extraction::empty($reader->name(), 'The file is missing from disk.');
        } else {
            $x = $this->run($reader, $bytes, $version->mime_type, $type);
        }

        return $version->extractions()->create([
            'reader' => $x->reader,
            'model' => $x->model,
            'fields' => $x->fields ?: null,
            'confidence' => $x->confidence ?: null,
            'raw_text' => $x->rawText !== null ? mb_substr($x->rawText, 0, 20000) : null,
            'mrz_valid' => $x->mrzValid,
            'status' => DocumentExtraction::PROPOSED,
            'error' => $x->error !== null ? mb_substr($x->error, 0, 255) : null,
        ]);
    }

    /** Read bytes without a stored version (bulk uploads). */
    public function run(Reader $reader, string $bytes, string $mime, string $type): Extraction
    {
        // A PDF with a text layer (an e-visa, a bank statement) needs no OCR
        // at all, whichever reader is configured.
        if ($mime === 'application/pdf' && trim($text = PdfText::all($bytes)) !== '') {
            $x = new Extraction('pdf-text', rawText: $text);
            FieldParser::apply($x, $type);

            return $x;
        }

        try {
            return $reader->read($bytes, $mime, $type);
        } catch (\Throwable $e) {
            Log::warning('Document reading failed', ['reader' => $reader->name(), 'error' => $e->getMessage()]);

            return Extraction::empty($reader->name(), $e->getMessage());
        }
    }
}
