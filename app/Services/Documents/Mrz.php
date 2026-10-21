<?php

namespace App\Services\Documents;

/**
 * The two lines at the bottom of a passport (ICAO TD3), read and checked.
 *
 * Each of the passport number, date of birth and expiry carries its own
 * check digit, and a composite digit covers all three, so a reading that
 * passes is almost certainly right and one that fails is flagged before a
 * person looks. OCR confuses O/0, I/1, S/5, B/8, Z/2; the digit-only
 * fields are repaired with the digit reading before checking.
 */
final class Mrz
{
    /** @return array<string, mixed>|null  Fields plus `valid` and `checks`, or null when no MRZ is there. */
    public static function parse(string $text): ?array
    {
        $lines = self::candidateLines($text);

        if ($lines === null) {
            return null;
        }

        [$l1, $l2] = $lines;

        $passportNumber = rtrim(substr($l2, 0, 9), '<');
        $passportCheck = $l2[9];
        $nationality = substr($l2, 10, 3);
        $dob = self::digits(substr($l2, 13, 6));
        $dobCheck = self::digit($l2[19]);
        $sex = $l2[20];
        $expiry = self::digits(substr($l2, 21, 6));
        $expiryCheck = self::digit($l2[27]);
        $personal = substr($l2, 28, 14);
        $personalCheck = $l2[42];
        $composite = self::digit($l2[43]);

        $checks = [
            'passport_number' => self::checkDigit(substr($l2, 0, 9)) === self::digit($passportCheck),
            'date_of_birth' => self::checkDigit($dob) === $dobCheck,
            'expires_on' => self::checkDigit($expiry) === $expiryCheck,
            'composite' => self::checkDigit(substr($l2, 0, 10).$dob.$dobCheck.$expiry.$expiryCheck.$personal.$personalCheck) === $composite,
        ];

        $names = explode('<<', substr($l1, 5), 2);

        return [
            'document_type' => $l1[0],
            'issuing_country' => str_replace('<', '', substr($l1, 2, 3)),
            'surname' => self::words($names[0] ?? ''),
            'given_names' => self::words($names[1] ?? ''),
            'passport_number' => str_replace('<', '', $passportNumber),
            'nationality' => str_replace('<', '', $nationality),
            'date_of_birth' => self::date($dob, past: true),
            'gender' => match ($sex) {
                'M' => 'male', 'F' => 'female', default => null
            },
            'expires_on' => self::date($expiry, past: false),
            // The three fields that matter each carry their own digit; the
            // composite also covers the optional personal number, which OCR
            // often smudges, so it is reported but does not decide validity.
            'valid' => $checks['passport_number'] && $checks['date_of_birth'] && $checks['expires_on'],
            'checks' => $checks,
            'lines' => [$l1, $l2],
        ];
    }

    /** @return array{string, string}|null */
    private static function candidateLines(string $text): ?array
    {
        $lines = [];

        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $clean = strtoupper(preg_replace('/[^A-Z0-9<]/i', '', str_replace(' ', '', $line)) ?? '');

            if (strlen($clean) >= 40 && strlen($clean) <= 46 && substr_count($clean, '<') >= 4) {
                $lines[] = str_pad(substr($clean, 0, 44), 44, '<');
            }
        }

        for ($i = 0; $i < count($lines) - 1; $i++) {
            if (str_starts_with($lines[$i], 'P')) {
                return [$lines[$i], $lines[$i + 1]];
            }
        }

        return null;
    }

    public static function checkDigit(string $input): int
    {
        $weights = [7, 3, 1];
        $sum = 0;

        foreach (str_split($input) as $i => $char) {
            $value = match (true) {
                ctype_digit($char) => (int) $char,
                ctype_alpha($char) => ord($char) - 55,
                default => 0,
            };
            $sum += $value * $weights[$i % 3];
        }

        return $sum % 10;
    }

    /** Letters that OCR mistakes for digits, put back as digits. */
    private static function digits(string $value): string
    {
        return strtr($value, ['O' => '0', 'Q' => '0', 'D' => '0', 'I' => '1', 'L' => '1', 'Z' => '2', 'S' => '5', 'B' => '8', 'G' => '6', '<' => '0']);
    }

    private static function digit(string $char): int
    {
        return (int) self::digits($char);
    }

    private static function date(string $yymmdd, bool $past): ?string
    {
        if (! preg_match('/^(\d{2})(\d{2})(\d{2})$/', $yymmdd, $m)) {
            return null;
        }

        $yy = (int) $m[1];
        $thisYear = (int) date('y');
        // A birth date is in the past; an expiry is within fifteen years either way.
        $century = $past ? ($yy > $thisYear ? 1900 : 2000) : ($yy > $thisYear + 15 ? 1900 : 2000);
        $year = $century + $yy;

        if (! checkdate((int) $m[2], (int) $m[3], $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, (int) $m[2], (int) $m[3]);
    }

    private static function words(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', str_replace('<', ' ', $value)) ?? '');
    }
}
