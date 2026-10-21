<?php

namespace App\Services\Payments;

use App\Models\Account;
use App\Models\Booking;
use App\Models\ExcursionBooking;
use App\Models\Payment;
use App\Models\Pilgrim;
use App\Support\Money;

/**
 * From what a slip says, which booking is it for and does the amount fit?
 * A proposal for Finance, never a decision.
 */
final class SlipMatcher
{
    /**
     * @param  array<string, mixed>  $fields  amount, currency, reference, payee_account, remarks, payer_name
     * @return array<string, mixed>
     */
    public function propose(array $fields): array
    {
        $out = ['payable' => null, 'reason' => null, 'warnings' => []];
        $text = strtoupper(($fields['remarks'] ?? '').' '.($fields['reference'] ?? '').' '.($fields['payer_name'] ?? ''));

        if (preg_match('/HJZ-?[BX]-?\d{4}-?\d{4}/', $text, $m)) {
            $ref = preg_replace('/^HJZ-?([BX])-?(\d{4})-?(\d{4})$/', 'HJZ-$1-$2-$3', $m[0]);
            $out['payable'] = Booking::query()->where('reference', $ref)->first() ?? ExcursionBooking::query()->where('reference', $ref)->first();
            $out['reason'] = $out['payable'] ? 'reference in the slip' : null;
        }

        if ($out['payable'] === null && preg_match('/\b(A\d{6})\b/', $text, $m)) {
            $pilgrim = Pilgrim::findByIdNumber($m[1]);
            $booking = $pilgrim?->customer?->bookings()->live()->latest('id')->first();

            if ($booking) {
                $out['payable'] = $booking;
                $out['reason'] = 'ID number in the slip';
            }
        }

        if (filled($fields['reference'] ?? null) && Payment::query()->where('payer_reference', $fields['reference'])->succeeded()->exists()) {
            $out['warnings'][] = 'A payment with reference '.$fields['reference'].' was already verified.';
        }

        if (filled($fields['payee_account'] ?? null)) {
            $ours = Account::query()->where('kind', Account::BANK)->get()->first(fn (Account $a): bool => $a->account_number !== null && str_ends_with(preg_replace('/\D/', '', $fields['payee_account']) ?? '', substr(preg_replace('/\D/', '', $a->account_number) ?? '', -8)));

            if ($ours === null) {
                $out['warnings'][] = 'The account paid into is not one of ours.';
            } else {
                $out['account'] = $ours;
            }
        }

        if ($out['payable'] instanceof Booking && filled($fields['amount'] ?? null) && filled($fields['currency'] ?? null)) {
            try {
                $money = Money::ofMajor((string) $fields['amount'], strtoupper($fields['currency']));
                $balance = $out['payable']->balance($money->currency);

                if ($money->minor > $balance->minor) {
                    $out['warnings'][] = 'The slip is for '.$money->format().' but only '.$balance->format().' is due in that currency.';
                }
            } catch (\Throwable) {
            }
        }

        return $out;
    }
}
