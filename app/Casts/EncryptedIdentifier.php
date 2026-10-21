<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * An identifier (bank account number, ID number, passport number) encrypted
 * at rest under APP_KEY, readable by the app, unreadable in a database dump.
 *
 * Prefix-tolerant: a value without the `enc:` prefix is returned as it is,
 * so a row written before the cast existed still reads. A value that will
 * not decrypt reads as null rather than throwing in the middle of a list.
 * It cannot be searched, by design.
 *
 * @implements CastsAttributes<string|null, string|null>
 */
class EncryptedIdentifier implements CastsAttributes
{
    private const PREFIX = 'enc:';

    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! str_starts_with($value, self::PREFIX)) {
            return $value;
        }

        try {
            return Crypt::decryptString(substr($value, strlen(self::PREFIX)));
        } catch (DecryptException) {
            return null;
        }
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::PREFIX.Crypt::encryptString((string) $value);
    }
}
