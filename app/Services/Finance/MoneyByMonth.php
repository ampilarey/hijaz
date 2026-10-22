<?php

namespace App\Services\Finance;

use App\Models\LedgerEntry;

/**
 * Income and expenses per month from the books, in MVR minor units at the
 * rate each entry snapshotted when it was recorded. Transfers and currency
 * purchases move money between our own accounts, so they are left out.
 */
final class MoneyByMonth
{
    /** @return array<string, array{label: string, in: int, out: int}> keyed Y-m, oldest first */
    public static function forLastMonths(int $count): array
    {
        $start = today()->startOfMonth()->subMonths($count - 1);
        $months = [];

        for ($i = 0; $i < $count; $i++) {
            $m = $start->copy()->addMonths($i);
            $months[$m->format('Y-m')] = ['label' => $m->format('M'), 'in' => 0, 'out' => 0];
        }

        LedgerEntry::query()->live()
            ->whereIn('kind', [LedgerEntry::INCOME, LedgerEntry::EXPENSE])
            ->whereDate('occurred_on', '>=', $start)
            ->get(['occurred_on', 'kind', 'direction', 'mvr_minor'])
            ->each(function (LedgerEntry $e) use (&$months): void {
                $key = $e->occurred_on->format('Y-m');

                if (! isset($months[$key]) || $e->mvr_minor === null) {
                    return;
                }

                $side = $e->direction === LedgerEntry::IN ? 'in' : 'out';
                // A refund is income going out; it lowers what came in rather than counting as a cost.
                if ($e->kind === LedgerEntry::INCOME) {
                    $months[$key]['in'] += $side === 'in' ? $e->mvr_minor : -$e->mvr_minor;
                } else {
                    $months[$key]['out'] += $side === 'out' ? $e->mvr_minor : -$e->mvr_minor;
                }
            });

        return $months;
    }
}
