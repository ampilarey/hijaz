<?php

namespace App\Services\Documents;

use App\Models\Document;
use App\Models\Island;
use App\Services\Documents\Readers\Extraction;

/**
 * Turns raw text (from Tesseract or a PDF's text layer) into proposed
 * fields for one document type. Best effort by regex and labels; staff
 * correct what it gets wrong, and the MRZ check says when a passport is
 * right.
 */
final class FieldParser
{
    public static function apply(Extraction $x, string $type): void
    {
        $text = (string) $x->rawText;

        match ($type) {
            Document::PASSPORT => self::passport($x, $text),
            Document::ID_CARD => self::idCard($x, $text),
            Document::VISA => self::visa($x, $text),
            Document::BANK_SLIP => self::slip($x, $text),
            default => null,
        };
    }

    private static function passport(Extraction $x, string $text): void
    {
        $mrz = Mrz::parse($text);

        if ($mrz !== null) {
            $x->mrzValid = $mrz['valid'];
            $c = fn (string $check): float => $mrz['checks'][$check] ? 0.97 : 0.4;
            $x->set('passport_number', $mrz['passport_number'], $c('passport_number'));
            $x->set('date_of_birth', $mrz['date_of_birth'], $c('date_of_birth'));
            $x->set('passport_expires_on', $mrz['expires_on'], $c('expires_on'));
            $x->set('gender', $mrz['gender'], 0.9);
            $x->set('nationality', $mrz['nationality'], 0.9);
            $x->set('passport_issuing_country', $mrz['issuing_country'], 0.9);
            $x->set('surname', $mrz['surname'], 0.85);
            $x->set('given_names', $mrz['given_names'], 0.85);
            $x->set('name_en', trim($mrz['given_names'].' '.$mrz['surname']), 0.8);
        }

        if (! $x->has('passport_number') && preg_match('/passport\s*(?:no|number|n°)?[.:\s]*([A-Z0-9]{6,10})/i', $text, $m)) {
            $x->set('passport_number', strtoupper($m[1]), 0.55);
        }

        $dates = self::dates($text);

        if (! $x->has('date_of_birth') && isset($dates[0])) {
            $x->set('date_of_birth', self::dateAfter($text, '/birth|dob|d\.o\.b/i') ?? $dates[0], 0.45);
        }

        if (! $x->has('passport_expires_on')) {
            $x->set('passport_expires_on', self::dateAfter($text, '/expir|valid until/i') ?? ($dates[count($dates) - 1] ?? null), 0.4);
        }

        if (! $x->has('passport_issued_on')) {
            $x->set('passport_issued_on', self::dateAfter($text, '/issue/i'), 0.4);
        }
    }

    private static function idCard(Extraction $x, string $text): void
    {
        if (preg_match('/\b([A4]\s?\d{6})\b/', $text, $m)) {
            $x->set('id_number', 'A'.substr(preg_replace('/\D/', '', $m[1]) ?? '', -6), 0.75);
        }

        if (preg_match('/\b(MALE|FEMALE)\b/i', $text, $m)) {
            $x->set('gender', strtolower($m[1]), 0.8);
        } elseif (preg_match('/\bsex\s*[:.]?\s*([MF])\b/i', $text, $m)) {
            $x->set('gender', $m[1] === 'M' ? 'male' : 'female', 0.7);
        }

        $dates = self::dates($text);
        $x->set('date_of_birth', self::dateAfter($text, '/birth|dob/i') ?? ($dates[0] ?? null), 0.5);
        $x->set('id_expires_on', self::dateAfter($text, '/expir/i') ?? (count($dates) > 1 ? $dates[count($dates) - 1] : null), 0.4);

        if (preg_match('/name\s*[:.]?\s*\n?\s*([A-Z][A-Z \'\-]{3,60})/i', $text, $m)) {
            $x->set('name_en', self::tidyName($m[1]), 0.6);
        } else {
            $x->set('name_en', self::longestNameLine($text), 0.35);
        }

        if (preg_match('/[\x{0780}-\x{07BF}][\x{0780}-\x{07BF}\s]{3,}/u', $text, $m)) {
            $x->set('name_dv', trim($m[0]), 0.3);
        }

        if (preg_match('/address\s*[:.]?\s*\n?\s*(.{5,120})/i', $text, $m)) {
            $x->set('address', trim(preg_replace('/\s+/', ' ', $m[1]) ?? ''), 0.45);
        }

        self::island($x, $text);
    }

    private static function visa(Extraction $x, string $text): void
    {
        if (preg_match('/visa\s*(?:no|number|n°)?[.:\s]*([A-Z0-9]{6,20})/i', $text, $m)) {
            $x->set('number', strtoupper($m[1]), 0.7);
        }

        if (preg_match('/passport\s*(?:no|number|n°)?[.:\s]*([A-Z0-9]{6,10})/i', $text, $m)) {
            $x->set('passport_number', strtoupper($m[1]), 0.7);
        }

        if (preg_match('/(?:full\s*)?name\s*[:.]?\s*([A-Z][A-Z \'\-]{3,80})/i', $text, $m)) {
            $x->set('name_en', self::tidyName($m[1]), 0.55);
        }

        $x->set('issued_at', self::dateAfter($text, '/issue|valid from|date of issue/i'), 0.5);
        $x->set('expires_at', self::dateAfter($text, '/expir|valid until|valid to|validity/i'), 0.5);
    }

    private static function slip(Extraction $x, string $text): void
    {
        if (preg_match('/(MVR|USD|SAR|Rf|\$)\s*([\d,]+(?:\.\d{2})?)/i', $text, $m)) {
            $cur = strtoupper($m[1]);
            $x->set('currency', match ($cur) {
                'RF' => 'MVR', '$' => 'USD', default => $cur
            }, 0.7);
            $x->set('amount', str_replace(',', '', $m[2]), 0.7);
        } elseif (preg_match('/amount\s*[:.]?\s*([\d,]+(?:\.\d{2})?)/i', $text, $m)) {
            $x->set('amount', str_replace(',', '', $m[1]), 0.5);
        }

        if (preg_match('/(?:reference|ref|transaction)\s*(?:no|id|number)?[.:\s#]*([A-Z0-9]{6,30})/i', $text, $m)) {
            $x->set('reference', strtoupper($m[1]), 0.65);
        }

        if (preg_match('/(?:to|beneficiary|payee)\s*(?:account|a\/c)?[.:\s]*(\d{10,16})/i', $text, $m)) {
            $x->set('payee_account', $m[1], 0.6);
        }

        if (preg_match('/(?:from|payer|sender|debit)\s*(?:account|a\/c)?[.:\s]*(\d{10,16})/i', $text, $m)) {
            $x->set('payer_account', $m[1], 0.5);
        }

        if (preg_match('/(?:remarks?|message|purpose|description)\s*[:.]?\s*(.{3,80})/i', $text, $m)) {
            $x->set('remarks', trim($m[1]), 0.5);
        }

        $x->set('paid_on', self::dates($text)[0] ?? null, 0.45);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /** @return list<string>  Every date in the text as YYYY-MM-DD, in order. */
    public static function dates(string $text): array
    {
        $out = [];

        if (preg_match_all('/\b(\d{1,2})[\/\-.\s](\d{1,2}|[A-Z]{3})[\/\-.\s](\d{4})\b/i', $text, $all, PREG_SET_ORDER)) {
            foreach ($all as $m) {
                $month = ctype_digit($m[2]) ? (int) $m[2] : self::month($m[2]);
                if ($month && checkdate($month, (int) $m[1], (int) $m[3])) {
                    $out[] = sprintf('%04d-%02d-%02d', (int) $m[3], $month, (int) $m[1]);
                }
            }
        }

        if (preg_match_all('/\b(\d{4})-(\d{2})-(\d{2})\b/', $text, $all, PREG_SET_ORDER)) {
            foreach ($all as $m) {
                if (checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                    $out[] = $m[0];
                }
            }
        }

        return $out;
    }

    private static function dateAfter(string $text, string $labelPattern): ?string
    {
        if (! preg_match($labelPattern.'', $text, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $after = substr($text, $m[0][1], 80);

        return self::dates($after)[0] ?? null;
    }

    private static function month(string $abbr): ?int
    {
        $months = ['JAN' => 1, 'FEB' => 2, 'MAR' => 3, 'APR' => 4, 'MAY' => 5, 'JUN' => 6, 'JUL' => 7, 'AUG' => 8, 'SEP' => 9, 'OCT' => 10, 'NOV' => 11, 'DEC' => 12];

        return $months[strtoupper($abbr)] ?? null;
    }

    private static function tidyName(string $raw): string
    {
        return trim(preg_replace('/\s+/', ' ', strtoupper($raw)) ?? '');
    }

    private static function longestNameLine(string $text): ?string
    {
        $best = null;

        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);

            if (! preg_match('/^[A-Z][A-Z \'\-]{5,60}$/', $line)) {
                continue;
            }

            if (preg_match('/MALDIVES|REPUBLIC|IDENTITY|CARD|NATIONAL|PASSPORT|DATE|BIRTH|ADDRESS|MALE|FEMALE|EXPIRY|ISSUE|NAME|SEX/', $line)) {
                continue;
            }

            if ($best === null || strlen($line) > strlen($best)) {
                $best = $line;
            }
        }

        return $best;
    }

    private static function island(Extraction $x, string $text): void
    {
        $upper = strtoupper($text);
        $longest = null;

        foreach (Island::query()->where('is_active', true)->get(['id', 'name', 'atoll_code']) as $island) {
            $name = strtoupper($island->name);

            if (strlen($name) >= 4 && str_contains($upper, $name) && ($longest === null || strlen($name) > strlen($longest->name))) {
                $longest = $island;
            }
        }

        if ($longest !== null) {
            $x->set('island_id', (string) $longest->id, 0.5);
        }
    }
}
