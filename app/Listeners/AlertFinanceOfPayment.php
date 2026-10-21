<?php

namespace App\Listeners;

use App\Events\PaymentRecorded;
use App\Models\Payment;
use App\Models\TelegramLink;
use App\Services\Telegram\Bot;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * Tells everyone who may verify payments, on Telegram, that one is waiting.
 * Queued, so the person recording the slip is not kept waiting on the Bot
 * API; the queue drains from cron within a minute.
 */
class AlertFinanceOfPayment implements ShouldQueue
{
    public function __construct(private readonly Bot $bot) {}

    public function handle(PaymentRecorded $event): void
    {
        if (! Bot::isConfigured()) {
            return;
        }

        $payment = $event->payment->fresh(['payable']);

        if (! $payment instanceof Payment || $payment->status !== Payment::AWAITING_REVIEW) {
            return;
        }

        $text = implode("\n", array_filter([
            '<b>Payment to verify</b>',
            e($payment->reference).' · '.e($payment->money()->format()).' · '.e(Payment::METHODS[$payment->method] ?? $payment->method),
            'For '.e($payment->payable?->payableLabel() ?? ''),
            $payment->payer_reference ? 'Bank ref '.e($payment->payer_reference) : null,
            $payment->slip() ? 'Slip attached.' : ($payment->method === Payment::BANK_TRANSFER ? 'No slip attached.' : null),
            '',
            'Verify: '.e(rtrim((string) config('app.url'), '/').'/portal/payments'),
        ]));

        TelegramLink::query()->active()->with('user')->each(function (TelegramLink $link) use ($payment, $text): void {
            $user = $link->user;

            if (! $user->is_active || $user->getKey() === $payment->recorded_by || ! $user->can('payment.verify')) {
                return;
            }

            try {
                $this->bot->sendMessage($link->chat_id, $text);
            } catch (\Throwable $e) {
                Log::warning('Payment alert failed', ['user_id' => $user->getKey(), 'error' => $e->getMessage()]);
            }
        });
    }
}
