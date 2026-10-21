<?php

namespace App\Services\Payments;

use App\Models\Document;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\TakesPayments;
use App\Services\Documents\DocumentWallet;
use App\Services\Documents\ReaderManager;
use App\Support\Currency;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * A slip arrives (portal upload, Telegram photo) and becomes a recorded
 * payment: read, matched to its booking by what it says, recorded through
 * the ledger, stored against the payment, and flagged where something
 * looks off. The same path whichever door it came in by.
 */
final class SlipIntake
{
    public function __construct(
        private readonly Ledger $ledger,
        private readonly DocumentWallet $wallet,
        private readonly ReaderManager $readers,
        private readonly SlipMatcher $matcher,
    ) {}

    /**
     * @param  (TakesPayments&Model)|null  $payable  Known already, or null to find it from the slip.
     * @param  Money|null  $amount  Typed by the person, or null to take it from the slip.
     * @return array{payment: ?Payment, payable: ?Model, reason: ?string, warnings: list<string>, problem: ?string}
     */
    public function record(?Model $payable, string $bytes, string $filename, string $mime, ?Money $amount = null, string $method = Payment::BANK_TRANSFER): array
    {
        $x = $this->readers->run($this->readers->reader(), $bytes, $mime, Document::BANK_SLIP);
        $proposal = $this->matcher->propose($x->fields);
        $payable ??= $proposal['payable'];
        $out = ['payment' => null, 'payable' => $payable, 'reason' => $payable === $proposal['payable'] ? $proposal['reason'] : null, 'warnings' => $proposal['warnings'], 'problem' => null];

        if (! $payable instanceof TakesPayments) {
            $out['problem'] = 'Nothing in the slip matched a booking reference or an ID number.';

            return $out;
        }

        if ($amount === null) {
            $currency = strtoupper((string) ($x->fields['currency'] ?? Currency::MVR));

            try {
                $amount = isset($x->fields['amount']) && Currency::isValid($currency) ? Money::ofMajor((string) $x->fields['amount'], $currency) : null;
            } catch (\InvalidArgumentException) {
                $amount = null;
            }
        }

        if ($amount === null) {
            $out['problem'] = 'The amount could not be read from the slip.';

            return $out;
        }

        try {
            $payment = $this->ledger->record($payable, $amount, $method, array_filter([
                'paid_on' => $x->fields['paid_on'] ?? null,
                'payer_reference' => $x->fields['reference'] ?? null,
                'payer_name' => $x->fields['payer_name'] ?? null,
                'account_id' => ($proposal['account'] ?? null)?->getKey(),
            ]));
        } catch (\InvalidArgumentException $e) {
            $out['problem'] = $e->getMessage();

            return $out;
        }

        $version = $this->wallet->store($payment, $bytes, $filename, $mime, Document::BANK_SLIP);
        $this->readers->record($version, $x);
        $payment->transactions()->create(['type' => PaymentTransaction::SLIP_UPLOADED, 'user_id' => Auth::id(), 'payload' => ['document_version_id' => $version->getKey()]]);

        if (isset($x->fields['amount'], $x->fields['currency'])) {
            try {
                $read = Money::ofMajor((string) $x->fields['amount'], strtoupper((string) $x->fields['currency']));

                if ($read->currency !== $payment->currency || $read->minor !== $payment->amount_minor) {
                    $out['warnings'][] = 'The slip reads '.$read->format().' but '.$payment->money()->format().' was recorded.';
                }
            } catch (\Throwable) {
            }
        }

        $out['payment'] = $payment;

        return $out;
    }
}
