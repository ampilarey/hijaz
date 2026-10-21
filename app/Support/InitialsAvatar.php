<?php

namespace App\Support;

/**
 * A staff avatar drawn here rather than fetched from a third party.
 *
 * Filament's default provider builds a URL on ui-avatars.com from the
 * person's name, which tells a service Hijaz has no agreement with who works
 * here on every page load. A data: URI needs no request and renders offline.
 */
final class InitialsAvatar
{
    public static function forName(?string $name): string
    {
        $background = htmlspecialchars(Brand::GREEN_DARK, ENT_QUOTES);
        $initials = htmlspecialchars(self::initials($name), ENT_QUOTES);

        $svg = <<<SVG
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" width="64" height="64">
                <rect width="64" height="64" fill="{$background}"/>
                <text x="32" y="32" fill="#FFFFFF" font-family="system-ui, sans-serif"
                      font-size="26" font-weight="600" text-anchor="middle"
                      dominant-baseline="central">{$initials}</text>
            </svg>
            SVG;

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    /** The first letter of each of the first two words, leading punctuation stripped. */
    public static function initials(?string $name): string
    {
        return str((string) $name)
            ->trim()
            ->explode(' ')
            ->map(fn (string $segment): string => mb_substr(ltrim($segment, "'\"(["), 0, 1))
            ->filter(fn (string $letter): bool => $letter !== '')
            ->take(2)
            ->map(fn (string $letter): string => mb_strtoupper($letter))
            ->implode('');
    }
}
