<?php

namespace App\Services\Telegram;

use App\Models\Booking;
use App\Models\BookingPilgrim;
use App\Models\Document;
use App\Models\Payment;
use App\Models\PaymentSchedule;
use App\Models\Trip;
use App\Models\TripChecklistItem;
use App\Models\User;
use App\Support\Settings;

/**
 * The morning message: what is waiting for this person, by what they may
 * do, then the trips ahead. Each phase adds its own section.
 */
class DailySummary
{
    public function for(User $user): string
    {
        $lines = [
            '<b>'.e(now()->translatedFormat('l j F')).'</b>',
            'Good morning, '.e($user->name).'.',
            '',
        ];

        $queue = [];

        if ($user->can('payment.verify')) {
            $n = Payment::query()->awaitingReview()->count();
            $queue[] = $n > 0 ? $n.' payment'.($n === 1 ? '' : 's').' to verify (/slips)' : null;
        }

        if ($user->can('booking.viewAny')) {
            $n = Booking::query()->where('status', Booking::DRAFT)->whereHas('seatHolds', fn ($h) => $h->whereNull('released_at')->whereBetween('expires_at', [now(), now()->addDays(2)]))->count();
            $queue[] = $n > 0 ? $n.' booking'.($n === 1 ? '' : 's').' whose held seats lapse within two days' : null;
        }

        if ($user->can('document.accept')) {
            $n = Document::query()->forPilgrims()->pending()->whereHas('versions', fn ($v) => $v->whereNull('superseded_at')->whereHas('extractions', fn ($e) => $e->where('status', 'proposed')))->count();
            $queue[] = $n > 0 ? $n.' reading'.($n === 1 ? '' : 's').' to accept' : null;
        }

        if ($user->can('document.verify')) {
            $n = Document::query()->forPilgrims()->pending()->whereDoesntHave('versions', fn ($v) => $v->whereNull('superseded_at')->whereHas('extractions', fn ($e) => $e->where('status', 'proposed')))->count();
            $queue[] = $n > 0 ? $n.' cop'.($n === 1 ? 'y' : 'ies').' to verify' : null;
        }

        if ($user->can('checklist.viewAny')) {
            $n = TripChecklistItem::query()->open()->whereDate('due_on', '<', today())->whereHas('trip', fn ($q) => $q->live())->count();
            $queue[] = $n > 0 ? $n.' checklist item'.($n === 1 ? '' : 's').' overdue' : null;
        }

        if ($user->can('booking.viewAny')) {
            $n = PaymentSchedule::query()->whereDate('due_on', '<', today())->whereHas('booking', fn ($b) => $b->where('status', Booking::CONFIRMED))->with('booking')->get()->filter(fn (PaymentSchedule $s): bool => $s->isOverdue())->count();
            $queue[] = $n > 0 ? $n.' instalment'.($n === 1 ? '' : 's').' overdue' : null;
        }

        if ($user->can('pilgrim.viewAny')) {
            $months = (int) Settings::get(Settings::PASSPORT_VALIDITY_MONTHS);
            $n = 0;

            foreach (Trip::query()->upcoming()->get() as $trip) {
                $n += BookingPilgrim::query()
                    ->whereIn('booking_id', Booking::query()->where('trip_id', $trip->getKey())->live()->select('id'))
                    ->whereHas('pilgrim', fn ($p) => $p->whereNotNull('passport_expires_on')->whereDate('passport_expires_on', '<', $trip->returns_on->copy()->addMonths($months)))
                    ->count();
            }

            $queue[] = $n > 0 ? $n.' passport'.($n === 1 ? '' : 's').' expiring inside the window for a booked trip' : null;
        }

        $queue = array_values(array_filter($queue));

        if ($queue === []) {
            $lines[] = 'Nothing is waiting for you.';
        } else {
            $lines[] = '<b>Waiting for you</b>';
            foreach ($queue as $item) {
                $lines[] = '• '.e($item);
            }
        }

        if ($user->can('trip.viewAny')) {
            $trips = Trip::query()->upcoming()->limit(3)->get();

            if ($trips->isNotEmpty()) {
                $lines[] = '';
                $lines[] = '<b>Trips ahead</b>';

                foreach ($trips as $trip) {
                    $lines[] = '• '.e($trip->code).' · '.e($trip->departs_on->format('j M')).' · '.$trip->seatsTaken().'/'.$trip->capacity_total.' seats';
                }
            }
        }

        $lines[] = '';
        $lines[] = 'Portal: '.e(rtrim((string) config('app.url'), '/').'/portal');

        return implode("\n", $lines);
    }
}
