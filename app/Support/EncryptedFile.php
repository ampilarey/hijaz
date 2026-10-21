<?php

namespace App\Support;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;

/**
 * Scans written as ciphertext under APP_KEY. A layer, not a safe: on shared
 * hosting somebody with a shell has both the files and the key. It stops a
 * stray backup or a copied folder from being a pile of passports.
 */
final class EncryptedFile
{
    public const MAGIC = "HIJAZENC\x01";

    public static function enabled(): bool
    {
        return config('documents.encrypt_at_rest') === true;
    }

    public static function put(string $disk, string $path, string $contents): bool
    {
        return Storage::disk($disk)->put($path, self::wrap($contents));
    }

    public static function contents(string $disk, string $path): ?string
    {
        $raw = Storage::disk($disk)->get($path);

        return $raw === null ? null : self::unwrap($raw);
    }

    public static function wrap(string $contents): string
    {
        if (! self::enabled()) {
            return $contents;
        }

        return self::MAGIC.Crypt::encryptString(base64_encode($contents));
    }

    public static function unwrap(string $raw): string
    {
        if (! str_starts_with($raw, self::MAGIC)) {
            return $raw;
        }

        return (string) base64_decode(Crypt::decryptString(substr($raw, strlen(self::MAGIC))), true);
    }
}
