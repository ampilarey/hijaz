<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Bookings\BookingResource;
use App\Filament\Resources\LedgerEntries\LedgerEntryResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\Trips\TripResource;
use App\Models\Booking;
use App\Models\BookingPilgrim;
use App\Models\Payment;
use App\Models\Trip;
use App\Models\User;
use App\Services\Finance\MoneyByMonth;
use App\Support\Money;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The figures at the top of the home page. Each card shows only to a role
 * that may see what it counts, so Registration never sees the money.
 */
class DashboardStats extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected static bool $isLazy = false;

    /** Two cards a row on a phone, three from a tablet up. */
    protected int|array|null $columns = ['default' => 2, '@md' => 3, '!@md' => 3];

    public static function canView(): bool
    {
        $user = Filament::auth()->user();

        return $user !== null && ($user->can('trip.viewAny') || $user->can('ledger.viewAny') || $user->can('payment.verify') || ($user->can('booking.viewAny') && $user->can('payment.viewAny')));
    }

    /** @return array<Stat> */
    protected function getStats(): array
    {
        /** @var User|null $user */
        $user = Filament::auth()->user();

        if ($user === null) {
            return [];
        }

        $stats = [];

        if ($user->can('trip.viewAny')) {
            $stats[] = $this->nextDeparture();
            $stats[] = $this->seats();
            $stats[] = $this->travellers();
        }

        if ($user->can('ledger.viewAny')) {
            $stats[] = $this->receivedThisMonth();
        }

        if ($user->can('booking.viewAny') && $user->can('payment.viewAny')) {
            $stats[] = $this->owed();
        }

        if ($user->can('payment.verify')) {
            $stats[] = $this->toVerify();
        }

        return $stats;
    }

    private function nextDeparture(): Stat
    {
        $trip = Trip::query()->live()->whereDate('departs_on', '>=', today())->orderBy('departs_on')->first();

        if ($trip === null) {
            return Stat::make('Next departure', 'None set')->description('No trip ahead')->color('gray');
        }

        $days = (int) today()->diffInDays($trip->departs_on);

        return Stat::make('Next departure', match ($days) {
            0 => 'Today',
            1 => 'Tomorrow',
            default => 'In '.$days.' days',
        })
            ->description($trip->name.' · '.$trip->departs_on->format('j M'))
            ->descriptionIcon('heroicon-m-paper-airplane')
            ->color($days <= 14 ? 'warning' : 'primary')
            ->url(TripResource::getUrl('edit', ['record' => $trip]));
    }

    private function seats(): Stat
    {
        $trips = Trip::query()->live()->whereDate('departs_on', '>=', today())->get();
        $taken = $trips->sum(fn (Trip $t): int => $t->seatsTaken());
        $total = (int) $trips->sum('capacity_total');
        $share = $total > 0 ? (int) round($taken * 100 / $total) : 0;

        return Stat::make('Seats filled', $taken.' of '.$total)
            ->description($trips->count() === 1 ? $share.'% of 1 trip ahead' : $share.'% across '.$trips->count().' trips ahead')
            ->descriptionIcon('heroicon-m-user-group')
            ->color($share >= 90 ? 'success' : 'primary')
            ->url(TripResource::getUrl());
    }

    private function travellers(): Stat
    {
        $ahead = fn ($q) => $q->whereHas('trip', fn ($t) => $t->live()->whereDate('departs_on', '>=', today()));
        $confirmed = BookingPilgrim::query()->whereHas('booking', fn ($b) => $ahead($b->where('status', Booking::CONFIRMED)))->count();
        $holding = Booking::query()->where('status', Booking::DRAFT)->tap($ahead)->count();

        return Stat::make('Pilgrims confirmed', (string) $confirmed)
            ->description($holding === 0 ? 'No booking still holding seats' : $holding.' '.str('booking')->plural($holding).' still holding seats')
            ->descriptionIcon('heroicon-m-ticket')
            ->url(BookingResource::getUrl());
    }

    /** From the books, in MVR at each entry's own dated rate: no conversion happens here. */
    private function receivedThisMonth(): Stat
    {
        $months = array_values(MoneyByMonth::forLastMonths(6));
        $now = $months[5];
        $before = $months[4];

        return Stat::make('Received this month', self::whole($now['in'], 'MVR'))
            ->description('Last month '.Money::ofMinor($before['in'] ?? 0, 'MVR')->format())
            ->descriptionIcon($now['in'] >= $before['in'] ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
            ->chart(array_map(fn (array $m): int => intdiv($m['in'], 100), $months))
            ->color('success')
            ->url(LedgerEntryResource::getUrl());
    }

    private function owed(): Stat
    {
        $totals = [];
        $count = 0;

        Booking::query()->where('status', Booking::CONFIRMED)->each(function (Booking $b) use (&$totals, &$count): void {
            $due = array_filter($b->balances(), fn (Money $m): bool => $m->minor > 0);
            $count += $due === [] ? 0 : 1;

            foreach ($due as $m) {
                $totals[$m->currency] = ($totals[$m->currency] ?? 0) + $m->minor;
            }
        });

        ksort($totals);
        $lead = array_key_exists('MVR', $totals) ? 'MVR' : array_key_first($totals);
        $others = collect($totals)->except($lead)->map(fn (int $m, string $c): string => Money::ofMinor($m, $c)->format())->implode(' · ');

        return Stat::make('Still owed to us', $lead === null ? 'Nothing' : self::whole($totals[$lead], $lead))
            ->description($count === 0 ? 'Every confirmed booking is paid' : trim($count.' '.str('booking')->plural($count).($others !== '' ? ' · '.$others : '')))
            ->descriptionIcon('heroicon-m-banknotes')
            ->color($count === 0 ? 'success' : 'danger')
            ->url(BookingResource::getUrl().'?activeTab=unpaid');
    }

    private function toVerify(): Stat
    {
        $waiting = Payment::query()->awaitingReview()->count();

        return Stat::make('Payments to verify', (string) $waiting)
            ->description($waiting === 0 ? 'All slips checked' : 'Slips waiting for Finance')
            ->descriptionIcon('heroicon-m-document-check')
            ->color($waiting === 0 ? 'success' : 'warning')
            ->url(PaymentResource::getUrl().'?activeTab=review');
    }

    /** A card has half a phone's width, so its figure is rounded to whole units; the reports keep the cents. */
    private static function whole(int $minor, string $currency): string
    {
        return Money::ofMinor((int) round($minor / 100) * 100, $currency)->format();
    }
}
