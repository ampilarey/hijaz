<?php

namespace App\Services\Documents\Readers;

use App\Models\Document;
use App\Services\Documents\FieldParser;
use App\Services\Documents\Mrz;
use Illuminate\Support\Facades\Http;

/**
 * Claude's vision API, which reads a phone photo of an ID card far better
 * than Tesseract, including the Dhivehi name. Off unless DOCUMENTS_READER
 * is set to anthropic and a key is present: the image leaves the server.
 */
final class AnthropicVisionReader implements Reader
{
    public function name(): string
    {
        return 'anthropic';
    }

    public function isAvailable(): bool
    {
        return filled(config('documents.anthropic.key'));
    }

    public function read(string $bytes, string $mime, string $type): Extraction
    {
        $x = new Extraction($this->name(), model: (string) config('documents.anthropic.model'));

        if ($mime === 'application/pdf') {
            // The API takes PDFs too, as a document block.
            $content = ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => base64_encode($bytes)]];
        } else {
            $content = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => base64_encode($bytes)]];
        }

        $response = Http::withHeaders([
            'x-api-key' => (string) config('documents.anthropic.key'),
            'anthropic-version' => '2023-06-01',
        ])->timeout((int) config('documents.anthropic.timeout', 45))
            ->post(rtrim((string) config('documents.anthropic.base_url'), '/').'/v1/messages', [
                'model' => $x->model,
                'max_tokens' => 800,
                'system' => 'You read scanned identity and travel documents for a Maldivian travel agency and answer only with a JSON object. Never guess: omit a field you cannot read. Dates as YYYY-MM-DD. Names in upper case as printed.',
                'messages' => [[
                    'role' => 'user',
                    'content' => [$content, ['type' => 'text', 'text' => self::prompt($type)]],
                ]],
            ])->throw();

        $text = collect($response->json('content', []))->firstWhere('type', 'text')['text'] ?? '';
        $x->rawText = $text;

        if (preg_match('/\{.*\}/s', $text, $m) && is_array($json = json_decode($m[0], true))) {
            foreach ($json as $field => $value) {
                if ($field === 'confidence' || $field === 'mrz') {
                    continue;
                }

                $x->set((string) $field, is_scalar($value) ? (string) $value : null, (float) ($json['confidence'][$field] ?? 0.8));
            }

            if (is_string($json['mrz'] ?? null)) {
                $mrz = Mrz::parse($json['mrz']);
                $x->mrzValid = $mrz['valid'] ?? null;
                $x->rawText .= "\n".$json['mrz'];
            }
        } else {
            FieldParser::apply($x, $type);
        }

        return $x;
    }

    private static function prompt(string $type): string
    {
        return match ($type) {
            Document::ID_CARD => 'This is a Maldives national ID card. Return JSON with: name_en, name_dv (Thaana), id_number (format A######), gender (male|female), date_of_birth, id_expires_on, address, and confidence: {field: 0-1}.',
            Document::PASSPORT => 'This is a passport. Return JSON with: surname, given_names, name_en, passport_number, nationality (3 letters), gender, date_of_birth, passport_issued_on, passport_expires_on, passport_issuing_country, mrz (the two machine-readable lines exactly), and confidence: {field: 0-1}.',
            Document::VISA => 'This is a Saudi visa or e-visa. Return JSON with: number (visa number), passport_number, name_en, issued_at, expires_at, entries, and confidence: {field: 0-1}.',
            Document::BANK_SLIP => 'This is a bank transfer slip or banking app screenshot. Return JSON with: amount (number), currency (MVR|USD|SAR), paid_on, reference, payer_name, payer_account, payee_account, remarks, and confidence: {field: 0-1}.',
            default => 'Return JSON with any identifying fields you can read and confidence: {field: 0-1}.',
        };
    }
}
