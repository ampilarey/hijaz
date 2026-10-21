<?php

namespace App\Services\Telegram;

use App\Models\TelegramLink;
use App\Models\TelegramUpdate;
use App\Support\AuditContext;
use Illuminate\Support\Facades\Auth;

/**
 * Turns one Telegram update into one reply.
 *
 * A linked chat acts as its staff account: the account is signed in for the
 * duration of the update, so policies, permissions and the audit trail see
 * a person, not a bot. An unknown chat is told how to link and nothing else.
 */
class Router
{
    public function __construct(
        private readonly Bot $bot,
        private readonly Linker $linker,
        private readonly DailySummary $summary,
    ) {}

    /** @param  array<string, mixed>  $update */
    public function handle(array $update, TelegramUpdate $record): void
    {
        $message = $update['message'] ?? null;

        if (! is_array($message) || ! isset($message['chat']['id'])) {
            $record->forceFill(['kind' => 'ignored', 'handled_at' => now()])->save();

            return;
        }

        $chatId = (int) $message['chat']['id'];
        $text = trim((string) ($message['text'] ?? ''));
        [$command, $argument] = $this->parse($text);

        $record->forceFill(['chat_id' => $chatId, 'kind' => 'message', 'command' => $command])->save();

        if ($command === '/start' && $argument !== '') {
            $this->link($chatId, $argument, $message['from'] ?? []);
            $record->forceFill(['handled_at' => now()])->save();

            return;
        }

        $link = $this->linker->linkForChat($chatId);

        if ($link === null) {
            $this->replyToStranger($chatId, $record);

            return;
        }

        $link->forceFill(['last_seen_at' => now()])->save();

        AuditContext::use(AuditContext::TELEGRAM);
        Auth::setUser($link->user);

        try {
            $this->dispatch($command, $argument, $link);
        } finally {
            Auth::forgetUser();
            AuditContext::reset();
        }

        $record->forceFill(['handled_at' => now()])->save();
    }

    private function dispatch(string $command, string $argument, TelegramLink $link): void
    {
        $user = $link->user;

        match ($command) {
            '/start', '/help' => $this->bot->sendMessage($link->chat_id, $this->help($user->name)),
            '/me' => $this->bot->sendMessage($link->chat_id, implode("\n", [
                '<b>'.e($user->name).'</b>',
                e($user->email),
                'Role: '.e($user->getRoleNames()->implode(', ') ?: 'none'),
                'Linked '.e($link->linked_at->diffForHumans()).'.',
            ])),
            '/today' => $this->bot->sendMessage($link->chat_id, $this->summary->for($user)),
            '/unlink' => $this->unlink($link),
            default => $this->bot->sendMessage($link->chat_id, "I don't know that one yet. Send /help for what I can do."),
        };
    }

    /** @param  array<string, mixed>  $from */
    private function link(int $chatId, string $code, array $from): void
    {
        $link = $this->linker->redeem($code, $chatId, [
            'username' => $from['username'] ?? null,
            'name' => trim(($from['first_name'] ?? '').' '.($from['last_name'] ?? '')) ?: null,
        ]);

        if ($link === null) {
            $this->bot->sendMessage($chatId, 'That code is not valid any more. Open the portal, go to Telegram, and get a new one.');

            return;
        }

        $this->bot->sendMessage($chatId, 'Linked to <b>'.e($link->user->name).'</b>. '.$this->help($link->user->name, greeting: false));
    }

    private function unlink(TelegramLink $link): void
    {
        $link->forceFill(['revoked_at' => now()])->save();
        $this->bot->sendMessage($link->chat_id, 'Unlinked. This chat no longer acts as '.e($link->user->name).'. Link again from the portal whenever you like.');
    }

    private function replyToStranger(int $chatId, TelegramUpdate $record): void
    {
        // One explanation per chat per day; a stranger does not get a conversation.
        $alreadyTold = TelegramUpdate::query()
            ->where('chat_id', $chatId)
            ->where('kind', 'stranger')
            ->where('received_at', '>=', now()->subDay())
            ->exists();

        $record->forceFill(['kind' => 'stranger', 'handled_at' => now()])->save();

        if ($alreadyTold) {
            return;
        }

        $this->bot->sendMessage($chatId, 'This is the Hijaz staff bot. To use it, sign in to the portal, open Telegram in the menu, and tap the link it gives you.');
    }

    private function help(string $name, bool $greeting = true): string
    {
        $lines = $greeting ? ['Hello '.e($name).'. I can:', ''] : ['I can:', ''];

        return implode("\n", array_merge($lines, [
            '/today — this morning\'s summary',
            '/me — who this chat acts as',
            '/unlink — stop acting as you',
            '/help — this list',
            '',
            'More commands arrive with each phase: trips, pilgrims, payments.',
        ]));
    }

    /** @return array{string, string} */
    private function parse(string $text): array
    {
        if (! str_starts_with($text, '/')) {
            return ['', $text];
        }

        $parts = preg_split('/\s+/', $text, 2) ?: [$text];
        $command = strtolower(preg_replace('/@\w+$/', '', $parts[0]) ?? $parts[0]);

        return [$command, trim($parts[1] ?? '')];
    }
}
