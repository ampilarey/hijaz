<?php

namespace App\Support;

/**
 * Mobile numbers in one shape, so the one typed at sign-in matches the one
 * saved on the account however either was written: "777 5123",
 * "+960 777-5123" and "9607775123" are all +9607775123.
 */
final class Phone
{
    public const MALDIVES = '960';

    public static function normalise(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $raw) ?? '';

        if ($digits === '') {
            return null;
        }

        // A local seven-digit mobile gets the country code; an international
        // number written with 00 loses the 00; anything else is taken as
        // already carrying its country code.
        if (strlen($digits) === 7) {
            $digits = self::MALDIVES.$digits;
        } elseif (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        return strlen($digits) >= 9 && strlen($digits) <= 15 ? '+'.$digits : null;
    }

    /** Whether what somebody typed at sign-in is a phone number rather than an email. */
    public static function looksLikeOne(string $value): bool
    {
        return ! str_contains($value, '@') && preg_match('/^\+?[\d\s\-()]{7,20}$/', trim($value)) === 1;
    }
}
