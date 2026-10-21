<?php

namespace App\Support;

/**
 * How the current change is arriving: the portal, the Telegram bot, the
 * console. Set by the entry point, read by AuditObserver, so a verification
 * done from a chat is recorded as such.
 */
final class AuditContext
{
    public const PORTAL = 'portal';

    public const TELEGRAM = 'telegram';

    public const CONSOLE = 'console';

    public const API = 'api';

    /** A customer on their own booking page, by one-time link. */
    public const SELF = 'self';

    private static ?string $channel = null;

    public static function channel(): string
    {
        if (self::$channel !== null) {
            return self::$channel;
        }

        return app()->runningInConsole() ? self::CONSOLE : self::PORTAL;
    }

    public static function use(string $channel): void
    {
        self::$channel = $channel;
    }

    public static function reset(): void
    {
        self::$channel = null;
    }
}
