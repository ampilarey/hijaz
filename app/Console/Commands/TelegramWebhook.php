<?php

namespace App\Console\Commands;

use App\Services\Telegram\Bot;
use Illuminate\Console\Command;

/**
 * Point the bot at this site. Run by the deploy script after every deploy;
 * harmless when the token is not set.
 */
class TelegramWebhook extends Command
{
    protected $signature = 'telegram:webhook {--remove : Stop Telegram calling this site} {--show : Print what Telegram currently has}';

    protected $description = 'Register (or remove) the Telegram webhook for this site';

    public function handle(Bot $bot): int
    {
        if (! Bot::isConfigured()) {
            $this->line('TELEGRAM_BOT_TOKEN is not set; the bot is off.');

            return self::SUCCESS;
        }

        if ($this->option('show')) {
            $this->line(json_encode($bot->getWebhookInfo()->json('result'), JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        if ($this->option('remove')) {
            $bot->deleteWebhook();
            $this->info('Webhook removed.');

            return self::SUCCESS;
        }

        // From APP_URL rather than route(), which in a console process
        // resolves against whatever host the generator was booted with.
        $url = rtrim((string) config('app.url'), '/').'/api/telegram/webhook';

        if (! str_starts_with($url, 'https://')) {
            $this->error("Telegram needs an https URL; APP_URL gives {$url}.");

            return self::FAILURE;
        }

        $bot->setWebhook($url);
        $this->info("Webhook set to {$url}");

        return self::SUCCESS;
    }
}
