<?php

namespace App\Services\Telegram;

use App\Models\Booking;
use App\Models\Document;
use App\Models\DocumentExtraction;
use App\Models\Payment;
use App\Models\Pilgrim;
use App\Models\TelegramLink;
use App\Models\TripChecklistItem;
use App\Models\User;
use App\Services\Payments\Ledger;
use App\Services\Pilgrims\Acceptor;

/**
 * Button taps. Every action is two taps: the first shows exactly what is
 * about to happen, the second does it, as the linked user, through the
 * same services and policies as the portal. A refusal needs a reason, so
 * it asks and waits for the next message.
 */
final class Callbacks
{
    public function __construct(
        private readonly Bot $bot,
        private readonly Ledger $ledger,
        private readonly Acceptor $acceptor,
    ) {}

    /** @param  array<string, mixed>  $callback  The callback_query object. */
    public function handle(string $data, TelegramLink $link, array $callback): void
    {
        $chatId = (int) ($callback['message']['chat']['id'] ?? $link->chat_id);
        $messageId = (int) ($callback['message']['message_id'] ?? 0);
        $original = (string) ($callback['message']['text'] ?? '');
        [$verb, $id] = array_pad(explode(':', $data, 2), 2, '');
        $user = $link->user;

        $toast = match ($verb) {
            'cancel' => $this->cancel($chatId, $messageId, $original),
            'pay.verify' => $this->payVerify($user, (int) $id, $chatId, $messageId),
            'pay.verify.yes' => $this->payVerifyYes($user, (int) $id, $chatId, $messageId),
            'pay.refuse' => $this->payRefuse($user, $link, (int) $id, $chatId, $messageId),
            'doc.show' => $this->docShow($user, (int) $id, $chatId),
            'doc.accept' => $this->docAccept($user, (int) $id, $chatId, $messageId),
            'doc.accept.yes' => $this->docAcceptYes($user, (int) $id, $chatId, $messageId),
            'check.tick' => $this->checkTick($user, (int) $id, $chatId, $messageId),
            'check.tick.yes' => $this->checkTickYes($user, (int) $id, $chatId, $messageId),
            default => 'That button is from an older version.',
        };

        $this->bot->answerCallbackQuery((string) ($callback['id'] ?? ''), $toast);
    }

    /** The text that arrived while this chat owed an answer. */
    public function resume(TelegramLink $link, string $text): void
    {
        $pending = $link->pending ?? [];
        $link->forceFill(['pending' => null])->save();
        $user = $link->user;

        if (($pending['action'] ?? null) === 'pay.refuse') {
            $payment = Payment::query()->find($pending['id'] ?? 0);

            if (! $payment instanceof Payment || $payment->isTerminal()) {
                $this->bot->sendMessage($link->chat_id, 'That payment is no longer waiting.');

                return;
            }

            if (! $user->can('payment.verify')) {
                $this->bot->sendMessage($link->chat_id, 'Your account cannot refuse payments.');

                return;
            }

            $this->ledger->refuse($payment, mb_substr(trim($text), 0, 255), $user);
            $this->bot->sendMessage($link->chat_id, '❌ '.e($payment->reference).' marked not received: '.e(trim($text)));

            return;
        }

        $this->bot->sendMessage($link->chat_id, 'Nothing was waiting for that. Send /help for what I can do.');
    }

    private function cancel(int $chatId, int $messageId, string $original): string
    {
        $this->edit($chatId, $messageId, $original, 'Cancelled.');

        return 'Cancelled';
    }

    // ── Payments ─────────────────────────────────────────────────────────

    private function payVerify(User $user, int $id, int $chatId, int $messageId): string
    {
        if (! $user->can('payment.verify')) {
            return 'Your account cannot verify payments.';
        }

        $payment = Payment::query()->with('payable')->find($id);

        if (! $payment instanceof Payment) {
            return 'No such payment.';
        }

        if (! in_array($payment->status, [Payment::PENDING, Payment::AWAITING_REVIEW], true)) {
            return 'Already '.strtolower($payment->statusLabel()).'.';
        }

        $payable = $payment->payable;

        if ($payable === null || ! in_array($payment->currency, $payable->paymentCurrencies(), true)) {
            return 'This payment settles another currency. Verify it in the portal, where the rate is applied.';
        }

        $this->bot->editMessageText($chatId, $messageId, implode("\n", [
            '<b>Verify '.e($payment->reference).'?</b>',
            e($payment->money()->format()).' · '.e(Payment::METHODS[$payment->method] ?? $payment->method).($payment->payer_reference ? ' · ref '.e($payment->payer_reference) : ''),
            'For '.e($payable->payableLabel()),
            'It settles the '.e($payment->currency).' balance in full. You have seen the money in the account.',
        ]), Bot::keyboard([['pay.verify.yes:'.$id => '✅ Yes, verify'], ['cancel:'.$id => 'Cancel']]));

        return '';
    }

    private function payVerifyYes(User $user, int $id, int $chatId, int $messageId): string
    {
        if (! $user->can('payment.verify')) {
            return 'Your account cannot verify payments.';
        }

        $payment = Payment::query()->find($id);

        if (! $payment instanceof Payment || ! in_array($payment->status, [Payment::PENDING, Payment::AWAITING_REVIEW], true)) {
            return 'That payment is no longer waiting.';
        }

        try {
            $this->ledger->verify($payment, null, 'Verified from Telegram', $user);
        } catch (\InvalidArgumentException $e) {
            return $e->getMessage();
        }

        $payable = $payment->fresh()->payable;
        $this->bot->editMessageText($chatId, $messageId, '✅ <b>'.e($payment->reference).' verified</b> by '.e($user->name)."\n".e($payment->money()->format()).' for '.e($payable?->payableLabel() ?? '')."\n".($payable instanceof Booking ? 'Booking now: '.e($payable->balanceSummary()).'.' : ''));

        return 'Verified';
    }

    private function payRefuse(User $user, TelegramLink $link, int $id, int $chatId, int $messageId): string
    {
        if (! $user->can('payment.verify')) {
            return 'Your account cannot refuse payments.';
        }

        $payment = Payment::query()->find($id);

        if (! $payment instanceof Payment || $payment->isTerminal()) {
            return 'That payment is no longer waiting.';
        }

        $link->forceFill(['pending' => ['action' => 'pay.refuse', 'id' => $id, 'message_id' => $messageId]])->save();
        $this->bot->sendMessage($chatId, 'Why is '.e($payment->reference).' not received? Reply with the reason, in one message.');

        return 'Reply with the reason';
    }

    // ── Readings ─────────────────────────────────────────────────────────

    private function docShow(User $user, int $id, int $chatId): string
    {
        if (! $user->can('document.view')) {
            return 'Your account cannot see copies.';
        }

        $x = DocumentExtraction::query()->with('version.document.documentable')->find($id);

        if (! $x instanceof DocumentExtraction) {
            return 'No such reading.';
        }

        $this->bot->sendMessage($chatId, $this->describe($x));

        return '';
    }

    private function docAccept(User $user, int $id, int $chatId, int $messageId): string
    {
        if (! $user->can('document.accept')) {
            return 'Your account cannot accept readings.';
        }

        $x = DocumentExtraction::query()->with('version.document.documentable')->find($id);

        if (! $x instanceof DocumentExtraction) {
            return 'No such reading.';
        }

        if (! $x->isProposed()) {
            return 'Already '.$x->status.'.';
        }

        $this->bot->editMessageText($chatId, $messageId, $this->describe($x)."\n\n<b>Accept every field above exactly as read?</b> Anything wrong is corrected in the portal instead.",
            Bot::keyboard([['doc.accept.yes:'.$id => '✅ Yes, accept'], ['cancel:'.$id => 'Cancel']]));

        return '';
    }

    private function docAcceptYes(User $user, int $id, int $chatId, int $messageId): string
    {
        if (! $user->can('document.accept')) {
            return 'Your account cannot accept readings.';
        }

        $x = DocumentExtraction::query()->with('version.document.documentable')->find($id);

        if (! $x instanceof DocumentExtraction || ! $x->isProposed()) {
            return 'That reading is no longer waiting.';
        }

        $this->acceptor->accept($x, $x->fields ?? [], false);
        $pilgrim = $x->version->document->documentable;
        $this->bot->editMessageText($chatId, $messageId, '✅ <b>Reading accepted</b> by '.e($user->name).' for '.e($pilgrim instanceof Pilgrim ? $pilgrim->name_en : '').'. Verifying the copy is a separate step in the portal.');

        return 'Accepted';
    }

    private function describe(DocumentExtraction $x): string
    {
        $document = $x->version->document;
        $pilgrim = $document->documentable;
        $lines = ['<b>'.e(Document::TYPES[$document->type] ?? $document->type).'</b> of <b>'.e($pilgrim instanceof Pilgrim ? $pilgrim->name_en : '').'</b> · read by '.e($x->reader)];
        $low = $x->lowConfidenceFields();

        foreach ($x->fields ?? [] as $field => $value) {
            $lines[] = e(ucfirst(str_replace('_', ' ', (string) $field))).': '.e((string) $value).(in_array($field, $low, true) ? ' ⚠' : '');
        }

        if ($document->type === Document::PASSPORT) {
            $lines[] = $x->mrz_valid === true ? 'MRZ check digits pass.' : ($x->mrz_valid === false ? '⚠ MRZ check digits FAIL.' : 'No MRZ read.');
        }

        return implode("\n", $lines);
    }

    // ── Checklist ────────────────────────────────────────────────────────

    private function checkTick(User $user, int $id, int $chatId, int $messageId): string
    {
        if (! $user->can('checklist.tick')) {
            return 'Your account cannot tick the checklist.';
        }

        $item = TripChecklistItem::query()->with('trip')->find($id);

        if (! $item instanceof TripChecklistItem) {
            return 'No such item.';
        }

        if ($item->isDone()) {
            return 'Already done.';
        }

        $this->bot->editMessageText($chatId, $messageId, '<b>Tick "'.e($item->title).'"</b> on '.e($item->trip->code).' as done?', Bot::keyboard([['check.tick.yes:'.$id => '✅ Yes, done'], ['cancel:'.$id => 'Cancel']]));

        return '';
    }

    private function checkTickYes(User $user, int $id, int $chatId, int $messageId): string
    {
        if (! $user->can('checklist.tick')) {
            return 'Your account cannot tick the checklist.';
        }

        $item = TripChecklistItem::query()->with('trip')->find($id);

        if (! $item instanceof TripChecklistItem || $item->isDone()) {
            return 'That item is no longer open.';
        }

        $item->markDone($user);
        $this->bot->editMessageText($chatId, $messageId, '✅ <b>'.e($item->title).'</b> ticked by '.e($user->name).' on '.e($item->trip->code).'.');

        return 'Done';
    }

    private function edit(int $chatId, int $messageId, string $original, string $suffix): void
    {
        if ($messageId > 0) {
            $this->bot->editMessageText($chatId, $messageId, e($original)."\n\n".$suffix);
        }
    }
}
