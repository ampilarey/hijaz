<?php

namespace App\Support;

/**
 * The three currencies Hijaz handles, each used where the business uses it:
 * MVR for packages and the office, USD for private rooms and transfers
 * abroad, SAR for everything paid in Saudi Arabia.
 */
final class Currency
{
    public const MVR = 'MVR';

    public const USD = 'USD';

    public const SAR = 'SAR';

    /** @var list<string> */
    public const ALL = [self::MVR, self::USD, self::SAR];

    /** Reporting base. Every report shows each currency on its own and a total in this one. */
    public const BASE = self::MVR;

    /** @return array<string, string> */
    public static function options(): array
    {
        return [
            self::MVR => 'MVR — rufiyaa',
            self::USD => 'USD — US dollars',
            self::SAR => 'SAR — Saudi riyals',
        ];
    }

    public static function isValid(string $code): bool
    {
        return in_array($code, self::ALL, true);
    }
}
