<?php

namespace App\Support;

/**
 * URLs for files served straight from public/ (logos, icons) that change
 * whenever the file does.
 *
 * Cloudflare and browsers cache these files for hours under their plain name,
 * so replacing a logo left visitors on the old one. Adding a short hash of the
 * contents as ?v= gives each version its own URL: a new file is fetched at
 * once and an unchanged one stays cached. Vite does the same for public/build.
 */
class PublicAsset
{
    /** @var array<string, string> */
    private static array $urls = [];

    public static function url(string $path): string
    {
        $path = ltrim($path, '/');

        return self::$urls[$path] ??= self::versioned($path);
    }

    private static function versioned(string $path): string
    {
        $file = public_path($path);
        $hash = is_file($file) ? substr((string) md5_file($file), 0, 10) : null;

        return asset($path).($hash ? "?v={$hash}" : '');
    }
}
