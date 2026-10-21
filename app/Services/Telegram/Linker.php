<?php

namespace App\Services\Telegram;

use App\Models\TelegramLink;
use App\Models\TelegramLinkCode;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Binds a staff account to a Telegram chat through a one-time code: shown
 * once in the portal, sent to the bot as /start CODE, hashed at rest.
 */
class Linker
{
    /** Mint a code for this user, invalidating any earlier unused one. Returns the plain code. */
    public function issueCode(User $user): string
    {
        TelegramLinkCode::query()->where('user_id', $user->getKey())->whereNull('used_at')->delete();

        $code = strtoupper(Str::random(8));

        TelegramLinkCode::query()->create([
            'user_id' => $user->getKey(),
            'code_hash' => hash('sha256', $code),
            'expires_at' => now()->addMinutes((int) config('telegram.link_code_minutes', 10)),
        ]);

        return $code;
    }

    public function deepLink(string $code): ?string
    {
        $username = app(Bot::class)->username();

        return $username === null ? null : "https://t.me/{$username}?start={$code}";
    }

    /**
     * Redeem a code from a chat. Any earlier link for the user or the chat
     * is revoked, so one account has one chat and one chat one account.
     *
     * @param  array{username?: string|null, name?: string|null}  $from
     */
    public function redeem(string $code, int $chatId, array $from = []): ?TelegramLink
    {
        $record = TelegramLinkCode::query()->where('code_hash', hash('sha256', strtoupper(trim($code))))->first();

        if ($record === null || ! $record->isUsable() || ! $record->user->is_active || ! $record->user->can('telegram.link')) {
            return null;
        }

        $record->forceFill(['used_at' => now()])->save();

        TelegramLink::query()->active()
            ->where(fn ($q) => $q->where('user_id', $record->user_id)->orWhere('chat_id', $chatId))
            ->update(['revoked_at' => now()]);

        return TelegramLink::query()->create([
            'user_id' => $record->user_id,
            'chat_id' => $chatId,
            'telegram_username' => $from['username'] ?? null,
            'telegram_name' => $from['name'] ?? null,
            'linked_at' => now(),
            'last_seen_at' => now(),
        ]);
    }

    public function revokeFor(User $user): void
    {
        TelegramLink::query()->active()->where('user_id', $user->getKey())->update(['revoked_at' => now()]);
    }

    public function linkForChat(int $chatId): ?TelegramLink
    {
        return TelegramLink::query()->active()->where('chat_id', $chatId)->with('user')->first();
    }
}
