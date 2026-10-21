<?php

namespace App\Services\Finance;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\BookingPilgrim;
use App\Models\Discount;
use App\Models\Document;
use App\Models\ExcursionBooking;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\Pilgrim;
use App\Models\Trip;
use App\Models\TripCost;
use App\Services\Invoices\Documents;
use App\Services\Operations\ListDocument;
use App\Services\Operations\Readiness;
use App\Services\Operations\Rooming;
use App\Support\Currency;
use App\Support\Money;
use App\Support\Settings;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The reports, each built from the records at the moment it is asked for.
 * Every currency is shown on its own and an MVR total appears beside it
 * only from the rates kept on the entries; a figure with no rate is said
 * to be missing rather than added in as nothing.
 */
final class Reports
{
    /** @var array<string, string> */
    public const KINDS = [
        'trip_pnl' => 'Trip profit and loss',
        'trip_summary' => 'Trip summary',
        'yearly' => 'Yearly income and expenses',
        'cash_book' => 'Cash book (one account)',
        'purchases' => 'Currency purchases',
        'outstanding' => 'Outstanding balances',
        'collections' => 'Payment collections by day',
        'discounts' => 'Discounts given',
        'documents' => 'Documents status and expiring passports',
        'excursions' => 'Additional trips: participation and income',
        'demographics' => 'Pilgrim demographics',
        'verifications' => 'Verification audit',
        'usd_list' => 'Bank USD list',
    ];

    public function __construct(private readonly Books $books, private readonly Documents $documents) {}

    // ── Trip ─────────────────────────────────────────────────────────────

    public function tripProfit(Trip $trip): ReportDocument
    {
        $entries = $trip->ledgerEntries()->live()->whereIn('kind', [LedgerEntry::INCOME, LedgerEntry::EXPENSE])->with('category')->get();
        $travellers = BookingPilgrim::query()->whereIn('booking_id', Booking::query()->where('trip_id', $trip->getKey())->whereIn('status', [Booking::CONFIRMED, Booking::COMPLETED])->select('id'))->count();

        $byCategory = fn (string $kind): Collection => $entries->where('kind', $kind)->groupBy(fn (LedgerEntry $e) => $e->category?->name ?? 'Uncategorised')
            ->map(fn (Collection $g) => ['by_currency' => $g->groupBy('currency')->map(fn (Collection $c) => (int) $c->sum('amount_minor'))->sortKeys()->all(), 'mvr' => $g->contains(fn (LedgerEntry $e) => $e->mvr_minor === null) ? null : (int) $g->sum('mvr_minor')]);

        $income = $byCategory(LedgerEntry::INCOME);
        $expense = $byCategory(LedgerEntry::EXPENSE);
        $rows = fn (Collection $groups): array => $groups->map(fn (array $g, string $name): array => ['category' => $name, 'amounts' => implode(' · ', array_map(fn (string $c, int $m) => Money::ofMinor($m, $c)->format(), array_keys($g['by_currency']), $g['by_currency'])), 'mvr' => $g['mvr'] === null ? 'no rate' : Money::ofMinor($g['mvr'], 'MVR')->format()])->values()->all();

        $incomeMvr = $income->contains(fn (array $g) => $g['mvr'] === null) ? null : (int) $income->sum('mvr');
        $expenseMvr = $expense->contains(fn (array $g) => $g['mvr'] === null) ? null : (int) $expense->sum('mvr');
        $margin = $incomeMvr === null || $expenseMvr === null ? null : $incomeMvr - $expenseMvr;

        // Costs as estimated or agreed on the trip, against what the books show paid.
        $estimated = $trip->costs()->get()->groupBy('currency')->map(fn (Collection $c) => (int) $c->sum(fn (TripCost $cost) => $cost->is_per_person ? $cost->amount_minor * max(1, $travellers) : $cost->amount_minor));
        $outstanding = $trip->bookings()->where('status', Booking::CONFIRMED)->get()->flatMap(fn (Booking $b) => $b->balances())->filter(fn (Money $m) => $m->minor > 0)->groupBy('currency')->map(fn (Collection $c, string $cur) => Money::ofMinor((int) $c->sum('minor'), $cur)->format())->values()->implode(' · ');

        $figures = [
            ['label' => 'Confirmed travellers', 'value' => (string) $travellers],
            ['label' => 'Income (MVR at dated rates)', 'value' => $incomeMvr === null ? 'a rate is missing' : Money::ofMinor($incomeMvr, 'MVR')->format()],
            ['label' => 'Expenses (MVR at dated rates)', 'value' => $expenseMvr === null ? 'a rate is missing' : Money::ofMinor($expenseMvr, 'MVR')->format()],
            ['label' => 'Margin', 'value' => $margin === null ? 'cannot be totalled until every entry has a rate' : Money::ofMinor($margin, 'MVR')->format().($travellers > 0 ? ' · '.Money::ofMinor(intdiv($margin, $travellers), 'MVR')->format().' per traveller' : '')],
            ['label' => 'Still owed by pilgrims', 'value' => $outstanding ?: 'nothing'],
            ['label' => 'Costs estimated on the trip', 'value' => $estimated->map(fn (int $m, string $c) => Money::ofMinor($m, $c)->format())->values()->implode(' · ') ?: 'none entered'],
        ];

        return new ReportDocument(
            title: 'Profit and loss',
            subtitle: $trip->name.' · '.$trip->code.' · '.$trip->departs_on->format('j M').' – '.$trip->returns_on->format('j M Y'),
            figures: $figures,
            sections: [
                new ListDocument('Income by category', 'From the books', ['category' => 'Category', 'amounts' => 'Amounts', 'mvr' => 'MVR'], $rows($income)),
                new ListDocument('Expenses by category', 'From the books', ['category' => 'Category', 'amounts' => 'Amounts', 'mvr' => 'MVR'], $rows($expense)),
            ],
            notes: ['Income is verified pilgrim money posted to the books; expenses are what Finance recorded. Estimates on the trip are a forecast, not a figure in the books.'],
            filename: strtolower($trip->code).'-profit-and-loss',
        );
    }

    public function tripSummary(Trip $trip): ReportDocument
    {
        $bookings = $trip->bookings()->get();
        $confirmed = $bookings->where('status', Booking::CONFIRMED);
        $travellers = BookingPilgrim::query()->whereIn('booking_id', $confirmed->pluck('id'))->with('pilgrim.documents.versions')->get();
        $received = [];
        $total = [];

        foreach ($confirmed as $b) {
            foreach ($b->currencies() as $c) {
                $total[$c] = ($total[$c] ?? 0) + $b->total($c)->minor;
                $received[$c] = ($received[$c] ?? 0) + $b->paidIn($c)->minor;
            }
        }

        ksort($total);
        $complete = $travellers->filter(fn (BookingPilgrim $bp): bool => collect(Document::REQUIRED_FOR_PILGRIMS)->every(fn (string $t): bool => $bp->pilgrim->document($t)?->isVerified() === true))->count();
        $readiness = app(Readiness::class);
        $rooming = app(Rooming::class);
        $hotelsSettled = $trip->hotels->every(fn ($h): bool => $rooming->isSettled($h));

        return new ReportDocument(
            title: 'Trip summary',
            subtitle: $trip->name.' · '.$trip->code.' · '.$trip->statusLabel(),
            figures: [
                ['label' => 'Seats', 'value' => $trip->seatsTaken().' of '.$trip->capacity_total.' ('.$trip->capacity_confirmed.' confirmed, '.$trip->capacity_held.' held)'],
                ['label' => 'Bookings', 'value' => $confirmed->count().' confirmed · '.$bookings->where('status', Booking::DRAFT)->count().' draft · '.$bookings->where('status', Booking::CANCELLED)->count().' cancelled'],
                ['label' => 'Sold', 'value' => implode(' · ', array_map(fn (string $c) => Money::ofMinor($total[$c], $c)->format(), array_keys($total))) ?: 'nothing yet'],
                ['label' => 'Received', 'value' => implode(' · ', array_map(fn (string $c) => Money::ofMinor($received[$c] ?? 0, $c)->format(), array_keys($total))) ?: '—'],
                ['label' => 'Outstanding', 'value' => implode(' · ', array_filter(array_map(fn (string $c) => ($total[$c] - ($received[$c] ?? 0)) > 0 ? Money::ofMinor($total[$c] - ($received[$c] ?? 0), $c)->format() : null, array_keys($total)))) ?: 'nothing'],
                ['label' => 'Copies complete and verified', 'value' => $complete.' of '.$travellers->count()],
                ['label' => 'Visas issued', 'value' => $travellers->where('visa_status', BookingPilgrim::VISA_ISSUED)->count().' of '.$travellers->count()],
                ['label' => 'Buses', 'value' => $trip->busGroups()->count().' · '.$travellers->whereNull('bus_group_id')->count().' travellers on none'],
                ['label' => 'Rooms', 'value' => $trip->hotels->isEmpty() ? 'no hotels' : ($hotelsSettled ? 'settled' : 'not settled')],
                ['label' => 'Readiness', 'value' => ($b = $readiness->blockers($trip)) === [] ? 'nothing blocks' : implode('; ', $b)],
            ],
            sections: [],
            filename: strtolower($trip->code).'-summary',
        );
    }

    // ── Books ────────────────────────────────────────────────────────────

    public function yearly(int $year): ReportDocument
    {
        $from = Carbon::create($year, 1, 1);
        $to = $from->copy()->endOfYear();
        $entries = LedgerEntry::query()->live()->whereIn('kind', [LedgerEntry::INCOME, LedgerEntry::EXPENSE])->between($from, $to)->with(['category', 'trip'])->get();

        $months = [];
        $categories = [];
        $trips = [];

        foreach ($entries as $e) {
            $m = $e->occurred_on->format('M');
            $sign = $e->kind === LedgerEntry::INCOME ? 1 : -1;
            $months[$m][$e->kind][$e->currency] = ($months[$m][$e->kind][$e->currency] ?? 0) + $e->amount_minor;
            $months[$m]['mvr'] = $e->mvr_minor === null ? ($months[$m]['mvr'] ?? 0) : (($months[$m]['mvr'] ?? 0) + $sign * $e->mvr_minor);
            $months[$m]['missing'] = ($months[$m]['missing'] ?? false) || $e->mvr_minor === null;
            $cat = ($e->category?->name ?? 'Uncategorised');
            $categories[$e->kind][$cat][$e->currency] = ($categories[$e->kind][$cat][$e->currency] ?? 0) + $e->amount_minor;
            $t = $e->trip?->code ?? 'Overheads';
            $trips[$t][$e->kind] = ($trips[$t][$e->kind] ?? 0) + ($e->mvr_minor ?? 0);
            $trips[$t]['missing'] = ($trips[$t]['missing'] ?? false) || $e->mvr_minor === null;
        }

        $fmt = fn (array $byCurrency): string => implode(' · ', array_map(fn (string $c, int $m) => Money::ofMinor($m, $c)->format(), array_keys($byCurrency), $byCurrency));
        $monthRows = [];

        foreach (['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'] as $m) {
            $monthRows[] = ['month' => $m, 'income' => $fmt($months[$m][LedgerEntry::INCOME] ?? []) ?: '—', 'expense' => $fmt($months[$m][LedgerEntry::EXPENSE] ?? []) ?: '—', 'net' => isset($months[$m]) ? (($months[$m]['missing'] ?? false) ? 'rate missing' : Money::ofMinor($months[$m]['mvr'], 'MVR')->format()) : '—'];
        }

        $catRows = fn (string $kind): array => collect($categories[$kind] ?? [])->map(fn (array $by, string $name) => ['category' => $name, 'amounts' => $fmt($by)])->values()->all();
        $tripRows = collect($trips)->map(fn (array $t, string $code) => ['trip' => $code, 'income' => Money::ofMinor($t[LedgerEntry::INCOME] ?? 0, 'MVR')->format(), 'expense' => Money::ofMinor($t[LedgerEntry::EXPENSE] ?? 0, 'MVR')->format(), 'net' => ($t['missing'] ?? false) ? 'rate missing' : Money::ofMinor(($t[LedgerEntry::INCOME] ?? 0) - ($t[LedgerEntry::EXPENSE] ?? 0), 'MVR')->format()])->values()->all();

        $incomeMvr = $entries->where('kind', LedgerEntry::INCOME)->sum('mvr_minor');
        $expenseMvr = $entries->where('kind', LedgerEntry::EXPENSE)->sum('mvr_minor');
        $missing = $entries->contains(fn (LedgerEntry $e) => $e->mvr_minor === null);

        return new ReportDocument(
            title: 'Income and expenses '.$year,
            subtitle: 'Every trip and the overheads, from the books',
            figures: [
                ['label' => 'Income (MVR)', 'value' => Money::ofMinor((int) $incomeMvr, 'MVR')->format().($missing ? ' + entries with no rate' : '')],
                ['label' => 'Expenses (MVR)', 'value' => Money::ofMinor((int) $expenseMvr, 'MVR')->format().($missing ? ' + entries with no rate' : '')],
                ['label' => 'Net (MVR)', 'value' => $missing ? 'incomplete: '.$entries->whereNull('mvr_minor')->count().' entries have no exchange rate for their date' : Money::ofMinor((int) ($incomeMvr - $expenseMvr), 'MVR')->format()],
                ['label' => 'Entries', 'value' => (string) $entries->count()],
            ],
            sections: [
                new ListDocument('By month', $year, ['month' => 'Month', 'income' => 'Income', 'expense' => 'Expenses', 'net' => 'Net (MVR)'], $monthRows),
                new ListDocument('Income by category', $year, ['category' => 'Category', 'amounts' => 'Amounts'], $catRows(LedgerEntry::INCOME)),
                new ListDocument('Expenses by category', $year, ['category' => 'Category', 'amounts' => 'Amounts'], $catRows(LedgerEntry::EXPENSE)),
                new ListDocument('By trip (MVR at dated rates)', $year, ['trip' => 'Trip', 'income' => 'Income', 'expense' => 'Expenses', 'net' => 'Net'], $tripRows),
            ],
            filename: 'income-and-expenses-'.$year,
        );
    }

    public function cashBook(Account $account, ?Carbon $from, ?Carbon $to): ReportDocument
    {
        $opening = $from ? $this->books->balance($account, $from->copy()->subDay()) : Money::ofMinor(0, $account->currency);
        $running = $opening->minor;
        $rows = [];

        foreach ($account->entries()->live()->between($from, $to)->with(['category', 'trip'])->orderBy('occurred_on')->orderBy('id')->get() as $e) {
            $running += $e->signedMinor();
            $rows[] = [
                'date' => $e->occurred_on->format('d/m/Y'),
                'description' => $e->description,
                'category' => $e->category?->name ?? $e->kindLabel(),
                'trip' => $e->trip?->code ?? '',
                'reference' => (string) $e->reference,
                'in' => $e->direction === LedgerEntry::IN ? $e->money()->format() : '',
                'out' => $e->direction === LedgerEntry::OUT ? $e->money()->format() : '',
                'balance' => Money::ofMinor($running, $account->currency)->format(),
            ];
        }

        return new ReportDocument(
            title: 'Cash book · '.$account->name,
            subtitle: $account->kindLabel().' · '.$account->currency.($from ? ' · '.$from->format('j M Y') : '').($to ? ' – '.$to->format('j M Y') : ''),
            figures: [
                ['label' => 'Opening balance', 'value' => $opening->format()],
                ['label' => 'Closing balance', 'value' => Money::ofMinor($running, $account->currency)->format()],
                ['label' => 'Balance today', 'value' => $this->books->balance($account)->format()],
            ],
            sections: [new ListDocument('Entries', $account->name, ['date' => 'Date', 'description' => 'Description', 'category' => 'Category', 'trip' => 'Trip', 'reference' => 'Ref', 'in' => 'In', 'out' => 'Out', 'balance' => 'Balance'], $rows, landscape: true)],
            filename: 'cash-book-'.Str::slug($account->name),
            landscape: true,
        );
    }

    public function currencyPurchases(?Carbon $from, ?Carbon $to): ReportDocument
    {
        $rows = [];
        $costMinor = 0;
        $missing = false;

        foreach (LedgerEntry::query()->live()->where('kind', LedgerEntry::EXCHANGE)->where('direction', LedgerEntry::IN)->between($from, $to)->with('trip')->orderBy('occurred_on')->get() as $e) {
            $meta = $e->meta ?? [];
            $paid = Money::ofMinor((int) ($meta['paid'] ?? 0), (string) ($meta['paid_currency'] ?? Currency::MVR));
            $received = $e->money();
            $realised = (float) ($meta['realised_rate'] ?? 0);
            $table = isset($meta['table_rate']) ? (float) $meta['table_rate'] : null;
            // The cost of buying at the bank's rate rather than the table rate, in what was paid.
            $cost = $table === null ? null : (int) round(($realised - $table) * $received->minor);
            $missing = $missing || $cost === null;
            $costMinor += $cost ?? 0;
            $rows[] = ['date' => $e->occurred_on->format('d/m/Y'), 'received' => $received->format(), 'paid' => $paid->format(), 'realised' => rtrim(rtrim(number_format($realised, 4, '.', ''), '0'), '.'), 'table' => $table === null ? 'none set' : rtrim(rtrim(number_format($table, 4, '.', ''), '0'), '.'), 'cost' => $cost === null ? '—' : Money::ofMinor($cost, $paid->currency)->format(), 'trip' => $e->trip?->code ?? '', 'where' => (string) $e->counterparty, 'reference' => (string) $e->reference];
        }

        return new ReportDocument(
            title: 'Currency purchases',
            subtitle: ($from ? $from->format('j M Y') : 'all time').($to ? ' – '.$to->format('j M Y') : ''),
            figures: [
                ['label' => 'Purchases', 'value' => (string) count($rows)],
                ['label' => 'Exchange cost against the table rate', 'value' => Money::ofMinor($costMinor, Currency::MVR)->format().($missing ? ' (some purchases had no table rate)' : '')],
            ],
            sections: [new ListDocument('Purchases', 'Realised rate is what was paid per unit received', ['date' => 'Date', 'received' => 'Received', 'paid' => 'Paid', 'realised' => 'Realised rate', 'table' => 'Table rate', 'cost' => 'Cost vs table', 'trip' => 'Trip', 'where' => 'Where', 'reference' => 'Ref'], $rows, landscape: true)],
            filename: 'currency-purchases',
            landscape: true,
        );
    }

    public function outstanding(?Trip $trip = null): ReportDocument
    {
        $rows = [];
        $totals = [];

        $bookings = Booking::query()->where('status', Booking::CONFIRMED)->when($trip, fn ($q) => $q->where('trip_id', $trip->getKey()))->with(['customer', 'trip', 'schedule'])->orderBy('confirmed_at')->get();

        foreach ($bookings as $b) {
            $due = array_filter($b->balances(), fn (Money $m) => $m->minor > 0);

            if ($due === []) {
                continue;
            }

            foreach ($due as $m) {
                $totals[$m->currency] = ($totals[$m->currency] ?? 0) + $m->minor;
            }

            $overdueSince = $b->schedule->filter(fn ($s) => $s->isOverdue())->min('due_on');
            $rows[] = [
                'booking' => $b->reference,
                'customer' => $b->customer->name,
                'phone' => (string) $b->customer->phone,
                'trip' => $b->trip->code,
                'departs' => $b->trip->departs_on->format('d/m/Y'),
                'due' => implode(' · ', array_map(fn (Money $m) => $m->format(), $due)),
                'age' => $b->confirmed_at ? (int) $b->confirmed_at->diffInDays(now()).' days' : '',
                'overdue' => $overdueSince ? 'since '.Carbon::parse($overdueSince)->format('d/m/Y') : '',
            ];
        }

        return new ReportDocument(
            title: 'Outstanding balances',
            subtitle: $trip ? $trip->name.' · '.$trip->code : 'Every confirmed booking',
            figures: array_merge(
                [['label' => 'Bookings owing', 'value' => (string) count($rows)]],
                collect($totals)->sortKeys()->map(fn (int $m, string $c) => ['label' => 'Owed in '.$c, 'value' => Money::ofMinor($m, $c)->format()])->values()->all(),
            ),
            sections: [new ListDocument('Who owes what', 'Confirmed bookings with a balance', ['booking' => 'Booking', 'customer' => 'Customer', 'phone' => 'Phone', 'trip' => 'Trip', 'departs' => 'Departs', 'due' => 'Due', 'age' => 'Confirmed', 'overdue' => 'Instalment overdue'], $rows, landscape: true)],
            filename: 'outstanding-balances',
            landscape: true,
        );
    }

    public function collections(?Trip $trip, ?Carbon $from, ?Carbon $to): ReportDocument
    {
        $payments = Payment::query()->succeeded()->with('payable')
            ->when($trip, fn ($q) => self::ofTrip($q, $trip))
            ->when($from, fn ($q) => $q->whereDate('paid_on', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('paid_on', '<=', $to))
            ->orderBy('paid_on')->get();

        $byDay = [];
        $byMethod = [];

        foreach ($payments as $p) {
            $day = $p->paid_on?->format('d/m/Y') ?? '—';
            $byDay[$day][$p->method][$p->currency] = ($byDay[$day][$p->method][$p->currency] ?? 0) + $p->amount_minor;
            $byMethod[$p->method][$p->currency] = ($byMethod[$p->method][$p->currency] ?? 0) + $p->amount_minor;
        }

        $fmt = fn (array $by): string => implode(' · ', array_map(fn (string $c, int $m) => Money::ofMinor($m, $c)->format(), array_keys($by), $by));
        $rows = [];

        foreach ($byDay as $day => $methods) {
            foreach ($methods as $method => $by) {
                $rows[] = ['date' => $day, 'method' => Payment::METHODS[$method] ?? $method, 'amounts' => $fmt($by)];
            }
        }

        return new ReportDocument(
            title: 'Payment collections',
            subtitle: ($trip ? $trip->code.' · ' : '').($from ? $from->format('j M Y') : 'all time').($to ? ' – '.$to->format('j M Y') : ''),
            figures: array_merge([['label' => 'Payments (net of refunds)', 'value' => (string) $payments->count()]], collect($byMethod)->map(fn (array $by, string $method) => ['label' => Payment::METHODS[$method] ?? $method, 'value' => $fmt($by)])->values()->all()),
            sections: [new ListDocument('By day and method', 'Verified payments; refunds count negative', ['date' => 'Date', 'method' => 'Method', 'amounts' => 'Amounts'], $rows)],
            filename: 'collections',
        );
    }

    public function discounts(?Trip $trip, ?Carbon $from, ?Carbon $to): ReportDocument
    {
        $rows = [];
        $totals = [];

        foreach (Discount::query()->with(['booking.customer', 'booking.trip', 'approver'])->when($trip, fn ($q) => $q->whereHas('booking', fn ($b) => $b->where('trip_id', $trip->getKey())))->when($from, fn ($q) => $q->whereDate('approved_at', '>=', $from))->when($to, fn ($q) => $q->whereDate('approved_at', '<=', $to))->orderBy('approved_at')->get() as $d) {
            if ($d->reversed_at === null) {
                $totals[$d->currency] = ($totals[$d->currency] ?? 0) + abs($d->amount_minor);
            }

            $rows[] = ['date' => $d->approved_at?->format('d/m/Y') ?? '', 'booking' => $d->booking->reference, 'customer' => $d->booking->customer->name, 'trip' => $d->booking->trip->code, 'amount' => Money::ofMinor(abs($d->amount_minor), $d->currency)->format().($d->kind === Discount::PERCENT ? ' ('.$d->value.'%)' : ''), 'reason' => $d->reason, 'approved' => $d->approver?->name ?? '', 'state' => $d->reversed_at ? 'taken back' : 'given'];
        }

        return new ReportDocument(
            title: 'Discounts given',
            subtitle: ($trip ? $trip->code.' · ' : '').($from ? $from->format('j M Y') : 'all time').($to ? ' – '.$to->format('j M Y') : ''),
            figures: array_merge([['label' => 'Discounts', 'value' => (string) count($rows)]], collect($totals)->sortKeys()->map(fn (int $m, string $c) => ['label' => 'Given in '.$c, 'value' => Money::ofMinor($m, $c)->format()])->values()->all()),
            sections: [new ListDocument('Each discount', 'With its reason and approver', ['date' => 'Date', 'booking' => 'Booking', 'customer' => 'Customer', 'trip' => 'Trip', 'amount' => 'Amount', 'reason' => 'Reason', 'approved' => 'Approved by', 'state' => 'State'], $rows, landscape: true)],
            filename: 'discounts',
            landscape: true,
        );
    }

    public function documentsStatus(Trip $trip): ReportDocument
    {
        $months = (int) Settings::get(Settings::PASSPORT_VALIDITY_MONTHS);
        $rows = [];
        $counts = ['verified' => 0, 'missing' => 0, 'expiring' => 0];

        foreach (BookingPilgrim::query()->whereIn('booking_id', Booking::query()->where('trip_id', $trip->getKey())->live()->select('id'))->with(['pilgrim.documents.versions', 'booking.customer'])->get() as $bp) {
            $p = $bp->pilgrim;
            $state = fn (string $t): string => match (true) {
                ($d = $p->document($t)) === null || $d->currentVersion() === null => 'missing',
                $d->isVerified() => 'verified',
                default => 'to check',
            };
            $expiring = $p->passportOkFor($trip->returns_on) === false;
            $counts['expiring'] += $expiring ? 1 : 0;
            $all = collect(Document::REQUIRED_FOR_PILGRIMS)->map($state);
            $counts['verified'] += $all->every(fn ($s) => $s === 'verified') ? 1 : 0;
            $counts['missing'] += $all->contains('missing') ? 1 : 0;
            $rows[] = ['name' => $p->name_en, 'family' => $bp->booking->customer->name, 'id_card' => $state(Document::ID_CARD), 'passport' => $state(Document::PASSPORT), 'expiry' => $p->passport_expires_on?->format('d/m/Y') ?? '', 'window' => $expiring ? 'expires inside '.$months.' months of return' : '', 'photo' => $state(Document::PHOTO), 'visa' => $bp->visaLabel()];
        }

        return new ReportDocument(
            title: 'Documents status',
            subtitle: $trip->name.' · '.$trip->code,
            figures: [
                ['label' => 'Travellers', 'value' => (string) count($rows)],
                ['label' => 'All three copies verified', 'value' => (string) $counts['verified']],
                ['label' => 'Missing at least one copy', 'value' => (string) $counts['missing']],
                ['label' => 'Passports expiring inside the window', 'value' => (string) $counts['expiring']],
            ],
            sections: [new ListDocument('Each traveller', 'ID card, passport, photo, visa', ['name' => 'Name', 'family' => 'Family', 'id_card' => 'ID card', 'passport' => 'Passport', 'expiry' => 'Expiry', 'window' => 'Window', 'photo' => 'Photo', 'visa' => 'Visa'], $rows, landscape: true)],
            filename: strtolower($trip->code).'-documents-status',
            landscape: true,
        );
    }

    public function excursions(Trip $trip): ReportDocument
    {
        $rows = [];
        $income = 0;

        foreach ($trip->excursions as $x) {
            $xbs = ExcursionBooking::query()->where('trip_excursion_id', $x->getKey())->where('status', '!=', ExcursionBooking::CANCELLED)->withCount('participants')->get();
            $sold = (int) $xbs->sum('total_minor');
            $paid = (int) $xbs->sum('paid_minor');
            $income += $paid;
            $rows[] = ['trip' => $x->title, 'price' => $x->priceLabel(), 'going' => (string) $xbs->sum('participants_count'), 'sold' => Money::ofMinor($sold, $x->currency)->format(), 'received' => Money::ofMinor($paid, $x->currency)->format(), 'due' => Money::ofMinor($sold - $paid, $x->currency)->format(), 'cost' => $x->cost_minor ? Money::ofMinor($x->cost_minor, $x->cost_currency ?? $x->currency)->format() : ''];
        }

        return new ReportDocument(
            title: 'Additional trips',
            subtitle: $trip->name.' · '.$trip->code,
            figures: [['label' => 'Received so far', 'value' => Money::ofMinor($income, 'SAR')->format()]],
            sections: [new ListDocument('Participation and income', 'Per additional trip', ['trip' => 'Additional trip', 'price' => 'Price', 'going' => 'Going', 'sold' => 'Sold', 'received' => 'Received', 'due' => 'Due', 'cost' => 'Cost to Hijaz'], $rows)],
            filename: strtolower($trip->code).'-additional-trips',
        );
    }

    public function demographics(?Trip $trip = null): ReportDocument
    {
        $pilgrims = $trip
            ? Pilgrim::query()->whereIn('id', BookingPilgrim::query()->whereIn('booking_id', Booking::query()->where('trip_id', $trip->getKey())->whereIn('status', [Booking::CONFIRMED, Booking::COMPLETED])->select('id'))->select('pilgrim_id'))->with('island')->get()
            : Pilgrim::query()->with('island')->get();
        $on = $trip?->departs_on ?? now();

        $bands = ['under 12' => 0, '12–17' => 0, '18–39' => 0, '40–59' => 0, '60–74' => 0, '75 and over' => 0, 'unknown' => 0];

        foreach ($pilgrims as $p) {
            $age = $p->ageOn($on);
            $band = match (true) {
                $age === null => 'unknown', $age < 12 => 'under 12', $age < 18 => '12–17', $age < 40 => '18–39', $age < 60 => '40–59', $age < 75 => '60–74', default => '75 and over',
            };
            $bands[$band]++;
        }

        $islands = $pilgrims->groupBy(fn (Pilgrim $p) => $p->island?->label ?? 'not recorded')->map->count()->sortDesc();
        $genders = $pilgrims->groupBy(fn (Pilgrim $p) => $p->gender ?? 'not recorded')->map->count();

        return new ReportDocument(
            title: 'Pilgrim demographics',
            subtitle: $trip ? $trip->name.' · '.$trip->code.' · ages on departure' : 'Everyone registered',
            figures: array_merge([['label' => 'Pilgrims', 'value' => (string) $pilgrims->count()]], $genders->map(fn (int $n, string $g) => ['label' => ucfirst($g), 'value' => (string) $n])->values()->all()),
            sections: [
                new ListDocument('By age', '', ['band' => 'Age', 'count' => 'Pilgrims'], collect($bands)->map(fn (int $n, string $b) => ['band' => $b, 'count' => (string) $n])->values()->all()),
                new ListDocument('By island', '', ['island' => 'Island', 'count' => 'Pilgrims'], $islands->map(fn (int $n, string $i) => ['island' => $i, 'count' => (string) $n])->values()->all()),
            ],
            filename: ($trip ? strtolower($trip->code).'-' : '').'demographics',
        );
    }

    public function verifications(?Trip $trip, ?Carbon $from, ?Carbon $to): ReportDocument
    {
        $rows = [];

        $docs = Document::query()->whereNotNull('verified_at')->with(['verifier', 'documentable'])
            ->when($from, fn ($q) => $q->whereDate('verified_at', '>=', $from))->when($to, fn ($q) => $q->whereDate('verified_at', '<=', $to))
            ->when($trip, fn ($q) => $q->where(fn ($w) => $w->where('trip_id', $trip->getKey())->orWhereHasMorph('documentable', [Pilgrim::class], fn ($p) => $p->whereIn('id', BookingPilgrim::query()->whereIn('booking_id', Booking::query()->where('trip_id', $trip->getKey())->select('id'))->select('pilgrim_id')))))
            ->orderBy('verified_at')->get();

        foreach ($docs as $d) {
            $rows[] = ['when' => $d->verified_at->format('d/m/Y H:i'), 'who' => $d->verifier?->name ?? '', 'what' => $d->typeLabel().' of '.($d->documentable instanceof Pilgrim ? $d->documentable->name_en : class_basename($d->documentable_type)), 'outcome' => $d->status, 'detail' => (string) $d->rejection_reason];
        }

        $payments = Payment::query()->whereNotNull('reviewed_at')->with(['reviewer', 'payable'])
            ->when($from, fn ($q) => $q->whereDate('reviewed_at', '>=', $from))->when($to, fn ($q) => $q->whereDate('reviewed_at', '<=', $to))
            ->when($trip, fn ($q) => self::ofTrip($q, $trip))
            ->orderBy('reviewed_at')->get();

        foreach ($payments as $p) {
            $rows[] = ['when' => $p->reviewed_at->format('d/m/Y H:i'), 'who' => $p->reviewer?->name ?? '', 'what' => $p->reference.' · '.$p->money()->format().' · '.($p->payable?->payableLabel() ?? ''), 'outcome' => $p->statusLabel(), 'detail' => (string) ($p->rejection_reason ?? $p->channel)];
        }

        usort($rows, fn ($a, $b) => strcmp($a['when'], $b['when']));

        return new ReportDocument(
            title: 'Verification audit',
            subtitle: ($trip ? $trip->code.' · ' : '').($from ? $from->format('j M Y') : 'all time').($to ? ' – '.$to->format('j M Y') : ''),
            figures: [['label' => 'Copies verified or rejected', 'value' => (string) $docs->count()], ['label' => 'Payments reviewed', 'value' => (string) $payments->count()], ['label' => 'Full trail', 'value' => 'Admin → Audit trail, '.AuditLog::query()->count().' entries']],
            sections: [new ListDocument('Who verified what', 'Copies and payments', ['when' => 'When', 'who' => 'Who', 'what' => 'What', 'outcome' => 'Outcome', 'detail' => 'Detail'], $rows, landscape: true)],
            filename: 'verification-audit',
            landscape: true,
        );
    }

    public function usdList(Trip $trip): ReportDocument
    {
        $rows = [];
        $seen = [];
        $total = 0;

        foreach ($trip->usdAllocations()->with(['bookingPilgrim.pilgrim', 'bookingPilgrim.booking.customer'])->get()->sortBy(fn ($a) => $a->bookingPilgrim->pilgrim->name_en) as $a) {
            $p = $a->bookingPilgrim->pilgrim;
            $key = $p->id_number ?: 'pilgrim-'.$p->id;

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $total += $a->amount_minor;
            $rows[] = ['n' => count($rows) + 1, 'name' => $p->name_en, 'id_number' => (string) $p->id_number, 'passport' => (string) $p->passport_number, 'dob' => $p->date_of_birth?->format('d/m/Y') ?? '', 'phone' => (string) $p->phone, 'amount' => $a->money()->major(), 'note' => (string) $a->note];
        }

        return new ReportDocument(
            title: 'USD allocation list',
            subtitle: $trip->name.' · '.$trip->code.' · departs '.$trip->departs_on->format('j F Y'),
            figures: [['label' => 'Pilgrims', 'value' => (string) count($rows)], ['label' => 'Total requested', 'value' => Money::ofMinor($total, 'USD')->format()]],
            sections: [new ListDocument('Pilgrims', 'One row per ID number', ['n' => '#', 'name' => 'Name', 'id_number' => 'ID number', 'passport' => 'Passport', 'dob' => 'Date of birth', 'phone' => 'Phone', 'amount' => 'USD', 'note' => 'Note'], $rows)],
            notes: ['Prepared for the bank\'s USD allocation to Umrah pilgrims. Columns follow the bank\'s current form; adjust in Settings when the bank changes it.'],
            filename: strtolower($trip->code).'-usd-list',
        );
    }

    /** Payments against the trip's bookings and its additional trips. */
    private static function ofTrip(Builder $query, Trip $trip): Builder
    {
        return $query->where(fn ($w) => $w
            ->where(fn ($b) => $b->where('payable_type', Booking::class)->whereIn('payable_id', Booking::query()->where('trip_id', $trip->getKey())->select('id')))
            ->orWhere(fn ($x) => $x->where('payable_type', ExcursionBooking::class)->whereIn('payable_id', ExcursionBooking::query()->whereHas('booking', fn ($b) => $b->where('trip_id', $trip->getKey()))->select('id'))));
    }

    // ── Rendering ────────────────────────────────────────────────────────

    public function pdf(ReportDocument $report, bool $letter = false): PdfDocument
    {
        return Pdf::loadView('pdf.report', ['report' => $report, 'issuer' => $this->documents->issuer(), 'letter' => $letter])
            ->setPaper('a4', $report->landscape ? 'landscape' : 'portrait');
    }
}
