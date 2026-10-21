<?php

namespace App\Listeners;

use App\Events\PaymentRecorded;
use App\Models\Payment;
use App\Services\Telegram\Alerts;
use App\Services\Telegram\Bot;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Tells everyone who may verify payments, on Telegram, that one is waiting.
 * Queued, so the person recording the slip is not kept waiting on the Bot
 * API; the queue drains from cron within a minute.
 */
class AlertFinanceOfPayment implements ShouldQueue
{
    public function __construct(private readonly Alerts $alerts) {}

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
            'Portal: '.e(Alerts::portalUrl('payments')),
        ]));

        $this->alerts->tell('payment.verify', $text, Bot::keyboard([[
            'pay.verify:'.$payment->getKey() => 'Verify',
            'pay.refuse:'.$payment->getKey() => 'Not received',
        ]]), $payment->recorded_by);
    }
}
