<?php

namespace App\Console\Commands;

use App\Models\TelegramLink;
use App\Services\Telegram\Bot;
use App\Services\Telegram\DailySummary;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class TelegramDaily extends Command
{
    protected $signature = 'telegram:daily';

    protected $description = 'Send the morning summary to every linked staff member';

    public function handle(Bot $bot, DailySummary $summary): int
    {
        if (! Bot::isConfigured()) {
            return self::SUCCESS;
        }

        $sent = 0;

        TelegramLink::query()->active()->with('user')->each(function (TelegramLink $link) use ($bot, $summary, &$sent): void {
            if (! $link->user->is_active) {
                return;
            }

            try {
                $bot->sendMessage($link->chat_id, $summary->for($link->user));
                $sent++;
            } catch (\Throwable $e) {
                Log::warning('Daily summary failed', ['user_id' => $link->user_id, 'error' => $e->getMessage()]);
            }
        });

        $this->info("Sent {$sent} summaries.");

        return self::SUCCESS;
    }
}
