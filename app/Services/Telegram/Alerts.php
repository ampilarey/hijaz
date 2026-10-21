<?php

namespace App\Services\Telegram;

use App\Models\TelegramLink;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * One message to everyone linked who holds a permission. The person who
 * caused the alert is left out: they know. Each send is its own request
 * and a failure is logged, never thrown, because a dead chat must not
 * stop the others hearing.
 */
final class Alerts
{
    public function __construct(private readonly Bot $bot) {}

    /** @param  array<string, mixed>  $extra  e.g. Bot::keyboard([...]) */
    public function tell(string $permission, string $text, array $extra = [], ?int $exceptUserId = null): int
    {
        if (! Bot::isConfigured()) {
            return 0;
        }

        $sent = 0;

        TelegramLink::query()->active()->with('user')->each(function (TelegramLink $link) use ($permission, $text, $extra, $exceptUserId, &$sent): void {
            $user = $link->user;

            if (! $user instanceof User || ! $user->is_active || $user->getKey() === $exceptUserId || ! $user->can($permission)) {
                return;
            }

            try {
                $this->bot->sendMessage($link->chat_id, $text, $extra);
                $sent++;
            } catch (\Throwable $e) {
                Log::warning('Telegram alert failed', ['user_id' => $user->getKey(), 'error' => $e->getMessage()]);
            }
        });

        return $sent;
    }

    public static function portalUrl(string $path = ''): string
    {
        return rtrim((string) config('app.url'), '/').'/portal'.($path === '' ? '' : '/'.ltrim($path, '/'));
    }
}
