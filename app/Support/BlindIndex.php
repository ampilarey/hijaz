<?php

namespace App\Support;

/**
 * A keyed hash of an identifier, stored beside its ciphertext so the same
 * number can be found again ("is this ID card already on file?") without
 * the database holding it in the clear. HMAC under APP_KEY: a dump without
 * the key cannot be turned back into ID numbers by guessing.
 */
final class BlindIndex
{
    public static function of(?string $value): ?string
    {
        $normalised = self::normalise($value);

        if ($normalised === null) {
            return null;
        }

        return hash_hmac('sha256', $normalised, 'blind-index|'.config('app.key'));
    }

    /** Upper case, no spaces or punctuation: "a 123456" and "A-123456" are the same card. */
    public static function normalise(?string $value): ?string
    {
        $clean = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $value));

        return $clean === '' ? null : $clean;
    }
}
