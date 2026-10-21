<?php

namespace App\Services\Telegram;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The Bot API, called directly. Each method is one request; none retries,
 * because a message sent twice is worse than one sent late.
 */
class Bot
{
    public static function isConfigured(): bool
    {
        return filled(config('telegram.bot_token'));
    }

    /** The secret Telegram echoes back on every webhook call. */
    public static function webhookSecret(): string
    {
        $configured = config('telegram.webhook_secret');

        if (filled($configured)) {
            return (string) $configured;
        }

        return substr(hash('sha256', 'telegram-webhook|'.config('app.key')), 0, 48);
    }

    public function sendMessage(int $chatId, string $text, array $extra = []): Response
    {
        return $this->call('sendMessage', array_merge([
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ], $extra));
    }

    public function sendDocument(int $chatId, string $filename, string $contents, ?string $caption = null): Response
    {
        return Http::timeout(30)
            ->attach('document', $contents, $filename)
            ->post($this->url('sendDocument'), array_filter(['chat_id' => $chatId, 'caption' => $caption]))
            ->throw();
    }

    public function setWebhook(string $url): Response
    {
        return $this->call('setWebhook', [
            'url' => $url,
            'secret_token' => self::webhookSecret(),
            'allowed_updates' => ['message', 'callback_query'],
            'drop_pending_updates' => false,
        ]);
    }

    public function deleteWebhook(): Response
    {
        return $this->call('deleteWebhook', []);
    }

    public function getWebhookInfo(): Response
    {
        return $this->call('getWebhookInfo', []);
    }

    public function getMe(): Response
    {
        return $this->call('getMe', []);
    }

    /** The bot's username, from config or from Telegram, cached for a day. */
    public function username(): ?string
    {
        $configured = config('telegram.bot_username');

        if (filled($configured)) {
            return ltrim((string) $configured, '@');
        }

        if (! self::isConfigured()) {
            return null;
        }

        return cache()->remember('telegram.bot_username', now()->addDay(), function (): ?string {
            $username = $this->getMe()->json('result.username');

            return is_string($username) ? $username : null;
        });
    }

    /** @param  array<string, mixed>  $params */
    private function call(string $method, array $params): Response
    {
        if (! self::isConfigured()) {
            throw new RuntimeException('TELEGRAM_BOT_TOKEN is not set.');
        }

        return Http::timeout(15)->asJson()->post($this->url($method), $params)->throw();
    }

    private function url(string $method): string
    {
        return rtrim((string) config('telegram.api_base'), '/').'/bot'.config('telegram.bot_token').'/'.$method;
    }
}
