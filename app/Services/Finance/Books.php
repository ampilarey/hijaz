<?php

namespace App\Services\Finance;

use App\Models\Account;
use App\Models\Booking;
use App\Models\Document;
use App\Models\ExchangeRate;
use App\Models\ExcursionBooking;
use App\Models\LedgerCategory;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\Trip;
use App\Models\User;
use App\Services\Documents\DocumentWallet;
use App\Support\Currency;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Writing the books. Every entry is one movement in one account, in that
 * account's currency, with an MVR figure at the table rate of the day for
 * reporting (null when no rate was set: the report says so rather than
 * inventing one). Verified pilgrim payments post themselves; everything
 * else is recorded by Finance with its evidence. Nothing is edited: a
 * wrong entry is voided and re-entered.
 */
final class Books
{
    public function __construct(private readonly DocumentWallet $wallet) {}

    /**
     * Income or an expense, in the account's currency.
     *
     * @param  array<string, mixed>  $details  counterparty, reference, trip_id, meta, evidence (bytes), evidence_name, evidence_mime
     */
    public function record(Account $account, string $kind, string $direction, Money $money, LedgerCategory $category, Carbon $on, string $description, array $details = [], ?User $by = null): LedgerEntry
    {
        $this->assertCurrency($account, $money);

        return DB::transaction(function () use ($account, $kind, $direction, $money, $category, $on, $description, $details, $by): LedgerEntry {
            $entry = LedgerEntry::query()->create(array_merge($this->mvr($money, $on), [
                'account_id' => $account->getKey(),
                'direction' => $direction,
                'kind' => $kind,
                'category_id' => $category->getKey(),
                'trip_id' => $details['trip_id'] ?? null,
                'occurred_on' => $on,
                'currency' => $money->currency,
                'amount_minor' => abs($money->minor),
                'description' => mb_substr($description, 0, 255),
                'counterparty' => isset($details['counterparty']) ? mb_substr((string) $details['counterparty'], 0, 160) : null,
                'reference' => isset($details['reference']) ? mb_substr((string) $details['reference'], 0, 80) : null,
                'group_key' => $details['group_key'] ?? null,
                'payment_id' => $details['payment_id'] ?? null,
                'meta' => $details['meta'] ?? null,
                'recorded_by' => $by?->getKey() ?? Auth::id(),
            ]));

            $this->attachEvidence($entry, $details);

            return $entry;
        });
    }

    /** Money moved between two accounts of the same currency: a handover, a cash deposit. */
    public function transfer(Account $from, Account $to, Money $money, Carbon $on, string $description, array $details = []): array
    {
        if ($from->currency !== $to->currency || $from->currency !== $money->currency) {
            throw new \InvalidArgumentException('A transfer keeps its currency. Buying currency is a different entry.');
        }

        if ($from->is($to)) {
            throw new \InvalidArgumentException('Pick two different accounts.');
        }

        return DB::transaction(function () use ($from, $to, $money, $on, $description, $details): array {
            $group = (string) Str::uuid();
            $base = array_merge($this->mvr($money, $on), [
                'kind' => LedgerEntry::TRANSFER,
                'occurred_on' => $on,
                'currency' => $money->currency,
                'amount_minor' => abs($money->minor),
                'description' => mb_substr($description, 0, 255),
                'reference' => $details['reference'] ?? null,
                'trip_id' => $details['trip_id'] ?? null,
                'group_key' => $group,
                'meta' => ['from_account_id' => $from->getKey(), 'to_account_id' => $to->getKey()],
            ]);

            $out = LedgerEntry::query()->create($base + ['account_id' => $from->getKey(), 'direction' => LedgerEntry::OUT, 'counterparty' => $to->name]);
            $in = LedgerEntry::query()->create($base + ['account_id' => $to->getKey(), 'direction' => LedgerEntry::IN, 'counterparty' => $from->name]);
            $this->attachEvidence($out, $details);

            return [$out, $in];
        });
    }

    /**
     * Buying dollars with rufiyaa: MVR out of one account, USD into another,
     * the realised rate on both, the bank's charges as a third entry. The
     * difference from the table rate is reported as exchange cost.
     *
     * @return list<LedgerEntry>
     */
    public function buyCurrency(Account $paidFrom, Money $paid, Account $receivedInto, Money $received, Carbon $on, ?Money $charges = null, array $details = []): array
    {
        $this->assertCurrency($paidFrom, $paid);
        $this->assertCurrency($receivedInto, $received);

        if ($paid->currency === $received->currency || $received->minor <= 0 || $paid->minor <= 0) {
            throw new \InvalidArgumentException('A currency purchase pays one currency and receives another.');
        }

        return DB::transaction(function () use ($paidFrom, $paid, $receivedInto, $received, $on, $charges, $details): array {
            $group = (string) Str::uuid();
            // The realised rate: how much of what was paid each unit received cost.
            $realised = round($paid->minor / $received->minor, 8);
            $description = 'Bought '.$received->format().' for '.$paid->format().($details['counterparty'] ?? null ? ' at '.$details['counterparty'] : '');
            $common = [
                'kind' => LedgerEntry::EXCHANGE,
                'occurred_on' => $on,
                'description' => mb_substr($description, 0, 255),
                'counterparty' => $details['counterparty'] ?? null,
                'reference' => $details['reference'] ?? null,
                'trip_id' => $details['trip_id'] ?? null,
                'group_key' => $group,
                'rate_snapshot' => $realised,
                'meta' => ['paid' => $paid->minor, 'paid_currency' => $paid->currency, 'received' => $received->minor, 'received_currency' => $received->currency, 'realised_rate' => $realised, 'table_rate' => ExchangeRate::inForce($received->currency, $paid->currency, $on)?->rate],
            ];

            $out = LedgerEntry::query()->create($common + ['account_id' => $paidFrom->getKey(), 'direction' => LedgerEntry::OUT, 'currency' => $paid->currency, 'amount_minor' => $paid->minor, 'mvr_minor' => $paid->currency === Currency::MVR ? $paid->minor : $this->mvr($paid, $on)['mvr_minor']]);
            $in = LedgerEntry::query()->create($common + ['account_id' => $receivedInto->getKey(), 'direction' => LedgerEntry::IN, 'currency' => $received->currency, 'amount_minor' => $received->minor, 'mvr_minor' => $paid->currency === Currency::MVR ? $paid->minor : $this->mvr($received, $on)['mvr_minor']]);
            $entries = [$out, $in];

            if ($charges instanceof Money && $charges->minor > 0) {
                $this->assertCurrency($paidFrom, $charges);
                $entries[] = $this->record($paidFrom, LedgerEntry::EXPENSE, LedgerEntry::OUT, $charges, $this->category('bank_charges'), $on, 'Bank charges on buying '.$received->format(), ['group_key' => $group, 'trip_id' => $details['trip_id'] ?? null, 'reference' => $details['reference'] ?? null]);
            }

            $this->attachEvidence($out, $details);

            return $entries;
        });
    }

    /**
     * A telegraphic transfer out: the expense itself, in the account's
     * currency, against its trip and category, plus the bank's charges.
     *
     * @return list<LedgerEntry>
     */
    public function sendTt(Account $from, Money $money, LedgerCategory $category, Carbon $on, string $beneficiary, array $details = [], ?Money $charges = null): array
    {
        return DB::transaction(function () use ($from, $money, $category, $on, $beneficiary, $details, $charges): array {
            $group = (string) Str::uuid();
            $entries = [$this->record($from, LedgerEntry::EXPENSE, LedgerEntry::OUT, $money, $category, $on, $details['description'] ?? ('TT to '.$beneficiary), [
                'counterparty' => $beneficiary,
                'reference' => $details['reference'] ?? null,
                'trip_id' => $details['trip_id'] ?? null,
                'group_key' => $group,
                'meta' => ['tt' => true, 'beneficiary_bank' => $details['beneficiary_bank'] ?? null, 'beneficiary_account' => $details['beneficiary_account'] ?? null],
                'evidence' => $details['evidence'] ?? null,
                'evidence_name' => $details['evidence_name'] ?? null,
                'evidence_mime' => $details['evidence_mime'] ?? null,
            ])];

            if ($charges instanceof Money && $charges->minor > 0) {
                $entries[] = $this->record($from, LedgerEntry::EXPENSE, LedgerEntry::OUT, $charges, $this->category('bank_charges'), $on, 'TT charges · '.$beneficiary, ['group_key' => $group, 'trip_id' => $details['trip_id'] ?? null, 'reference' => $details['reference'] ?? null]);
            }

            return $entries;
        });
    }

    public function void(LedgerEntry $entry, string $reason, ?User $by = null): void
    {
        if ($entry->isVoided()) {
            return;
        }

        DB::transaction(function () use ($entry, $reason, $by): void {
            // The other half of a transfer or exchange goes with it; the charges entry too.
            $entries = $entry->group_key ? LedgerEntry::query()->where('group_key', $entry->group_key)->live()->get() : collect([$entry]);

            foreach ($entries as $e) {
                $e->forceFill(['voided_at' => now(), 'voided_by' => $by?->getKey() ?? Auth::id(), 'void_reason' => mb_substr($reason, 0, 255)])->save();
            }
        });
    }

    /**
     * A verified payment is income in the account it went into; a refund is
     * money back out. Idempotent: the unique (payment, direction) index means
     * posting twice is a no-op.
     */
    public function postPayment(Payment $payment): ?LedgerEntry
    {
        if ($payment->status !== Payment::SUCCEEDED) {
            return null;
        }

        $direction = $payment->isRefund() ? LedgerEntry::OUT : LedgerEntry::IN;

        if (LedgerEntry::query()->where('payment_id', $payment->getKey())->where('direction', $direction)->exists()) {
            return null;
        }

        $account = $payment->account ?? Account::defaultFor($payment->method === Payment::CASH ? Account::CASH : Account::BANK, $payment->currency) ?? Account::defaultFor(Account::BANK, $payment->currency);

        if ($account === null) {
            return null;
        }

        $payable = $payment->payable;
        $tripId = match (true) {
            $payable instanceof Booking => $payable->trip_id,
            $payable instanceof ExcursionBooking => $payable->booking?->trip_id,
            default => null,
        };
        $category = $payment->isRefund()
            ? $this->category('refunds')
            : ($payable instanceof ExcursionBooking ? $this->category('excursion_sales') : ($payment->currency === Currency::USD ? $this->category('room_upgrades') : $this->category('package_sales')));

        return $this->record($account, $payment->isRefund() ? LedgerEntry::EXPENSE : LedgerEntry::INCOME, $direction, Money::ofMinor(abs($payment->amount_minor), $payment->currency), $category, $payment->paid_on ?? now(), ($payment->isRefund() ? 'Refund ' : 'Payment ').$payment->reference.' · '.($payable?->payableLabel() ?? ''), [
            'counterparty' => $payment->payer_name ?: ($payable?->customer?->name ?? null),
            'reference' => $payment->payer_reference,
            'trip_id' => $tripId,
            'payment_id' => $payment->getKey(),
        ], $payment->reviewer);
    }

    /** Succeeded payments not yet in the books, posted. For the nightly catch-up. */
    public function postMissingPayments(): int
    {
        $n = 0;

        Payment::query()->succeeded()->with(['payable', 'account', 'reviewer'])->orderBy('id')->each(function (Payment $p) use (&$n): void {
            if ($this->postPayment($p) !== null) {
                $n++;
            }
        });

        return $n;
    }

    public function balance(Account $account, ?Carbon $asOf = null): Money
    {
        $query = $account->entries()->live()->when($asOf, fn ($q) => $q->whereDate('occurred_on', '<=', $asOf));
        $in = (int) (clone $query)->where('direction', LedgerEntry::IN)->sum('amount_minor');
        $out = (int) (clone $query)->where('direction', LedgerEntry::OUT)->sum('amount_minor');

        return Money::ofMinor($in - $out, $account->currency);
    }

    public function category(string $slug): LedgerCategory
    {
        $category = LedgerCategory::bySlug($slug);

        if ($category === null) {
            $kind = isset(LedgerCategory::DEFAULTS[LedgerCategory::INCOME][$slug]) ? LedgerCategory::INCOME : LedgerCategory::EXPENSE;
            $category = LedgerCategory::query()->create(['kind' => $kind, 'slug' => $slug, 'name' => LedgerCategory::DEFAULTS[$kind][$slug] ?? ucfirst(str_replace('_', ' ', $slug)), 'sort_order' => 99]);
        }

        return $category;
    }

    /** @return array{mvr_minor: ?int, rate_snapshot: ?string} */
    public function mvr(Money $money, Carbon $on): array
    {
        if ($money->currency === Currency::MVR) {
            return ['mvr_minor' => abs($money->minor), 'rate_snapshot' => null];
        }

        $rate = ExchangeRate::inForce($money->currency, Currency::MVR, $on);

        return $rate === null
            ? ['mvr_minor' => null, 'rate_snapshot' => null]
            : ['mvr_minor' => (int) round(abs($money->minor) * (float) $rate->rate), 'rate_snapshot' => $rate->rate];
    }

    private function assertCurrency(Account $account, Money $money): void
    {
        if ($account->currency !== $money->currency) {
            throw new \InvalidArgumentException($account->name.' is a '.$account->currency.' account; this is '.$money->currency.'.');
        }
    }

    /** @param  array<string, mixed>  $details */
    private function attachEvidence(LedgerEntry $entry, array $details): void
    {
        if (! isset($details['evidence']) || ! is_string($details['evidence']) || $details['evidence'] === '') {
            return;
        }

        $this->wallet->store($entry, $details['evidence'], (string) ($details['evidence_name'] ?? 'evidence'), (string) ($details['evidence_mime'] ?? 'application/octet-stream'), Document::EVIDENCE, null, array_filter(['number' => $entry->reference]));
    }

    /** The trips with anything in the books, newest first, for filters. */
    public static function tripsWithEntries(): array
    {
        return Trip::query()->whereHas('ledgerEntries')->orderByDesc('departs_on')->get()->mapWithKeys(fn (Trip $t): array => [$t->id => $t->label])->all();
    }
}
