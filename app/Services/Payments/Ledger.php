<?php

namespace App\Services\Payments;

use App\Events\PaymentRecorded;
use App\Events\PaymentSettled;
use App\Models\Booking;
use App\Models\ExchangeRate;
use App\Models\ExcursionBooking;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentTransaction;
use App\Models\TakesPayments;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Money in and out, recorded then verified. The paid total of a payable is
 * never incremented: it is the sum of the allocations of its succeeded
 * payments, re-derived under a row lock on the payable. A payment keeps its
 * own currency; its allocations say which balance it settled, at what rate.
 */
final class Ledger
{
    /**
     * @param  array<string, mixed>  $details  paid_on, payer_name, payer_bank, payer_reference, notes, account_id
     */
    public function record(TakesPayments&Model $payable, Money $money, string $method, array $details = []): Payment
    {
        if ($money->minor <= 0) {
            throw new \InvalidArgumentException('A payment must be for more than nothing.');
        }

        // Money is taken against a price: until the trip's price covers everyone on the booking, nothing is owed.
        if ($payable instanceof Booking && $payable->awaitsPrice()) {
            throw new \InvalidArgumentException('This booking has no price yet. Set the trip\'s prices first; payments can be taken once everyone on it is priced.');
        }

        return DB::transaction(function () use ($payable, $money, $method, $details): Payment {
            $payment = Payment::query()->create(array_merge([
                'payable_type' => $payable->getMorphClass(),
                'payable_id' => $payable->getKey(),
                'method' => $method,
                'currency' => $money->currency,
                'amount_minor' => $money->minor,
                'paid_on' => $details['paid_on'] ?? now()->toDateString(),
            ], array_intersect_key($details, array_flip(['account_id', 'payer_name', 'payer_bank', 'payer_reference', 'notes']))));

            $payment->transactions()->create(['type' => PaymentTransaction::CREATED, 'to_status' => Payment::PENDING, 'amount_minor' => $money->minor, 'user_id' => Auth::id()]);

            // Cash in hand needs no slip and waits for nobody but Finance; a transfer waits for its slip too.
            $payment->transitionTo(Payment::AWAITING_REVIEW);

            DB::afterCommit(fn () => PaymentRecorded::dispatch($payment));

            return $payment;
        });
    }

    /**
     * Finance says the money is in. With no allocations given, the whole
     * payment settles the balance in its own currency. For a payment in
     * another currency, the caller says how much of which balance it
     * settles; the rate in force is snapshotted on each allocation.
     *
     * @param  list<array{currency: string, amount_minor: int}>|null  $allocations
     */
    public function verify(Payment $payment, ?array $allocations = null, ?string $note = null, ?User $actor = null): Payment
    {
        return DB::transaction(function () use ($payment, $allocations, $note, $actor): Payment {
            $payable = $this->lockedPayable($payment);
            $payment->refresh();

            $payment->transitionTo(Payment::SUCCEEDED, $note, $actor);
            $this->allocate($payment, $payable, $allocations);
            $this->recompute($payable);

            DB::afterCommit(fn () => PaymentSettled::dispatch($payment));

            return $payment->refresh();
        });
    }

    public function refuse(Payment $payment, string $reason, ?User $actor = null): Payment
    {
        return DB::transaction(function () use ($payment, $reason, $actor): Payment {
            $payable = $this->lockedPayable($payment);
            $payment->refresh();
            $payment->transitionTo(Payment::FAILED, $reason, $actor);
            $this->recompute($payable);

            return $payment->refresh();
        });
    }

    public function cancel(Payment $payment, string $reason, ?User $actor = null): Payment
    {
        return DB::transaction(function () use ($payment, $reason, $actor): Payment {
            $payable = $this->lockedPayable($payment);
            $payment->refresh();
            $payment->transitionTo(Payment::CANCELLED, $reason, $actor);
            $this->recompute($payable);

            return $payment->refresh();
        });
    }

    /** Money going back: a negative payment, verified at once because the decision was the review. */
    public function refund(Payment $payment, ?Money $amount = null, ?string $reason = null, ?User $actor = null): Payment
    {
        return DB::transaction(function () use ($payment, $amount, $reason, $actor): Payment {
            $payable = $this->lockedPayable($payment);
            $payment->refresh();

            if ($payment->status !== Payment::SUCCEEDED || $payment->isRefund()) {
                throw new \InvalidArgumentException('Only money that was received can be refunded.');
            }

            $minor = abs($amount instanceof Money ? $amount->minor : $payment->amount_minor);
            $left = $payment->amount_minor + (int) $payment->refunds()->succeeded()->sum('amount_minor');

            if ($minor === 0 || $minor > $left) {
                throw new \InvalidArgumentException('Up to '.Money::ofMinor(max(0, $left), $payment->currency)->format().' of that payment is left to refund.');
            }

            $refund = Payment::query()->create([
                'payable_type' => $payment->payable_type,
                'payable_id' => $payment->payable_id,
                'refund_of_id' => $payment->getKey(),
                'method' => $payment->method,
                'account_id' => $payment->account_id,
                'currency' => $payment->currency,
                'amount_minor' => -$minor,
                'paid_on' => now()->toDateString(),
                'notes' => $reason,
            ]);

            $refund->transactions()->create(['type' => PaymentTransaction::CREATED, 'to_status' => Payment::PENDING, 'amount_minor' => -$minor, 'user_id' => ($actor ?? Auth::user())?->getKey(), 'reason' => $reason]);
            $refund->transitionTo(Payment::SUCCEEDED, $reason, $actor);

            // Undo the original's allocations in proportion.
            $share = $minor / max(1, $payment->amount_minor);

            foreach ($payment->allocations as $allocation) {
                $refund->allocations()->create([
                    'currency' => $allocation->currency,
                    'amount_minor' => -(int) round($allocation->amount_minor * $share),
                    'rate_snapshot' => $allocation->rate_snapshot,
                    'exchange_rate_id' => $allocation->exchange_rate_id,
                    'allocated_by' => ($actor ?? Auth::user())?->getKey(),
                ]);
            }

            $this->recompute($payable);

            DB::afterCommit(fn () => PaymentSettled::dispatch($refund));

            return $refund->refresh();
        });
    }

    /** @return array<string, int> */
    public function recompute(TakesPayments&Model $payable): array
    {
        return DB::transaction(function () use ($payable): array {
            $locked = $this->lock($payable);

            $paid = PaymentAllocation::query()
                ->whereHas('payment', fn ($q) => $q->where('payable_type', $locked->getMorphClass())->where('payable_id', $locked->getKey())->succeeded())
                ->selectRaw('currency, sum(amount_minor) as total')
                ->groupBy('currency')
                ->pluck('total', 'currency')
                ->map(fn ($v): int => (int) $v)
                ->all();

            $locked->storePaid($paid);

            if ($payable->exists) {
                $payable->setRawAttributes($locked->getAttributes(), sync: true);
            }

            return $paid;
        });
    }

    /** @param  list<array{currency: string, amount_minor: int}>|null  $allocations */
    private function allocate(Payment $payment, TakesPayments&Model $payable, ?array $allocations): void
    {
        $payment->allocations()->delete();

        if ($allocations === null || $allocations === []) {
            $payment->allocations()->create(['currency' => $payment->currency, 'amount_minor' => $payment->amount_minor, 'allocated_by' => Auth::id()]);

            return;
        }

        $accounted = 0;

        foreach ($allocations as $a) {
            $currency = strtoupper($a['currency']);
            $amount = (int) $a['amount_minor'];

            if ($amount <= 0 || ! in_array($currency, $payable->paymentCurrencies(), true)) {
                continue;
            }

            if ($currency === $payment->currency) {
                $payment->allocations()->create(['currency' => $currency, 'amount_minor' => $amount, 'allocated_by' => Auth::id()]);
                $accounted += $amount;

                continue;
            }

            $rate = ExchangeRate::inForce($payment->currency, $currency, $payment->paid_on);

            if ($rate === null) {
                throw new \InvalidArgumentException("No exchange rate from {$payment->currency} to {$currency} is set for ".$payment->paid_on?->format('j M Y').'. Finance sets one under Money → Exchange rates.');
            }

            $payment->allocations()->create([
                'currency' => $currency,
                'amount_minor' => $amount,
                'rate_snapshot' => $rate->rate,
                'exchange_rate_id' => $rate->getKey(),
                'allocated_by' => Auth::id(),
            ]);

            // How much of the payment this used, in the payment's currency.
            $accounted += (int) round($amount / (float) $rate->rate);
        }

        $payment->transactions()->create(['type' => PaymentTransaction::ALLOCATED, 'payload' => $allocations, 'user_id' => Auth::id()]);

        if ($accounted > $payment->amount_minor * 1.02 + 100) {
            throw new \InvalidArgumentException('The allocations add up to more than the payment.');
        }
    }

    private function lockedPayable(Payment $payment): TakesPayments&Model
    {
        $payable = $payment->payable;

        if (! $payable instanceof TakesPayments) {
            throw new \LogicException('That payment points at something that does not take payments.');
        }

        return $this->lock($payable);
    }

    private function lock(TakesPayments&Model $payable): TakesPayments&Model
    {
        /** @var TakesPayments&Model $locked */
        $locked = $payable::query()->whereKey($payable->getKey())->lockForUpdate()->firstOrFail();

        return $locked;
    }

    /** The bookings and excursion bookings money can be paid against. */
    public static function payableClasses(): array
    {
        return [Booking::class, ExcursionBooking::class];
    }
}
