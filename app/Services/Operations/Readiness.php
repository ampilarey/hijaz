<?php

namespace App\Services\Operations;

use App\Models\Booking;
use App\Models\BookingPilgrim;
use App\Models\Document;
use App\Models\Trip;
use App\Models\TripChecklistItem;
use Illuminate\Support\Collection;

/**
 * Is this trip ready to go? Computed every time, never stored, as a list
 * of named concerns rather than a score: "three passports, one hotel
 * unsettled" is a morning's work; "72%" is nothing. Blocking means somebody
 * cannot travel; attention means somebody will have a bad time.
 */
final class Readiness
{
    public const BLOCKING = 'blocking';

    public const ATTENTION = 'attention';

    public function __construct(private readonly Rooming $rooming, private readonly Buses $buses) {}

    /**
     * @return list<array{area: string, severity: string, headline: string, detail: string, count: int, url: ?string}>
     */
    public function concerns(Trip $trip): array
    {
        $travellers = $this->confirmed($trip);

        return array_values(array_filter(array_merge(
            $this->documents($trip, $travellers),
            $this->passports($trip, $travellers),
            $this->identity($travellers),
            $this->visas($travellers),
            $this->tickets($trip, $travellers),
            $this->money($trip),
            $this->buses($trip, $travellers),
            $this->rooms($trip),
            $this->checklist($trip),
        )));
    }

    public function hasBlockers(Trip $trip): bool
    {
        foreach ($this->concerns($trip) as $c) {
            if ($c['severity'] === self::BLOCKING) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> Headlines of what blocks, for the person about to override. */
    public function blockers(Trip $trip): array
    {
        return array_values(array_map(fn (array $c): string => $c['headline'], array_filter($this->concerns($trip), fn (array $c): bool => $c['severity'] === self::BLOCKING)));
    }

    /** @return Collection<int, BookingPilgrim> */
    public function confirmed(Trip $trip): Collection
    {
        return BookingPilgrim::query()
            ->whereIn('booking_id', Booking::query()->where('trip_id', $trip->getKey())->where('status', Booking::CONFIRMED)->select('id'))
            ->with(['pilgrim.documents', 'booking.customer', 'busGroup'])
            ->get();
    }

    /** @param  Collection<int, BookingPilgrim>  $travellers */
    private function documents(Trip $trip, Collection $travellers): array
    {
        $missing = ['id_card' => 0, 'passport' => 0, 'photo' => 0];
        $unverified = 0;
        $people = 0;

        foreach ($travellers as $bp) {
            $short = false;

            foreach (Document::REQUIRED_FOR_PILGRIMS as $type) {
                $doc = $bp->pilgrim->document($type);

                if ($doc === null || $doc->currentVersion() === null) {
                    $missing[$type]++;
                    $short = true;
                } elseif (! $doc->isVerified()) {
                    $unverified++;
                    $short = true;
                }
            }

            $people += $short ? 1 : 0;
        }

        if ($people === 0) {
            return [];
        }

        $parts = array_filter([
            $missing['id_card'] ? $missing['id_card'].' ID card'.($missing['id_card'] === 1 ? '' : 's') : null,
            $missing['passport'] ? $missing['passport'].' passport'.($missing['passport'] === 1 ? '' : 's') : null,
            $missing['photo'] ? $missing['photo'].' photo'.($missing['photo'] === 1 ? '' : 's') : null,
            $unverified ? $unverified.' not yet verified' : null,
        ]);

        return [[
            'area' => 'documents',
            'severity' => $missing['passport'] > 0 ? self::BLOCKING : self::ATTENTION,
            'headline' => $people === 1 ? 'One traveller is missing copies' : $people.' travellers are missing copies',
            'detail' => 'Outstanding: '.implode(', ', $parts).'.',
            'count' => $people,
            'url' => route('filament.portal.resources.documents.index'),
        ]];
    }

    /** @param  Collection<int, BookingPilgrim>  $travellers */
    private function passports(Trip $trip, Collection $travellers): array
    {
        $short = $travellers->filter(fn (BookingPilgrim $bp): bool => $bp->pilgrim->passportOkFor($trip->returns_on) === false);
        $unknown = $travellers->filter(fn (BookingPilgrim $bp): bool => $bp->pilgrim->passportOkFor($trip->returns_on) === null);
        $out = [];

        if ($short->isNotEmpty()) {
            $out[] = ['area' => 'passports', 'severity' => self::BLOCKING, 'headline' => $short->count() === 1 ? 'One passport expires too soon' : $short->count().' passports expire too soon', 'detail' => $short->map(fn ($bp) => $bp->pilgrim->name_en.' ('.$bp->pilgrim->passport_expires_on?->format('j M Y').')')->implode(', ').'.', 'count' => $short->count(), 'url' => null];
        }

        if ($unknown->isNotEmpty()) {
            $out[] = ['area' => 'passports', 'severity' => self::ATTENTION, 'headline' => $unknown->count() === 1 ? 'One passport has no expiry on file' : $unknown->count().' passports have no expiry on file', 'detail' => $unknown->map(fn ($bp) => $bp->pilgrim->name_en)->implode(', ').'.', 'count' => $unknown->count(), 'url' => null];
        }

        return $out;
    }

    /** @param  Collection<int, BookingPilgrim>  $travellers */
    private function identity(Collection $travellers): array
    {
        $off = $travellers->filter(fn (BookingPilgrim $bp): bool => $bp->pilgrim->unexplainedMismatches() !== []);

        return $off->isEmpty() ? [] : [[
            'area' => 'identity', 'severity' => self::ATTENTION,
            'headline' => $off->count() === 1 ? 'One ID card and passport disagree' : $off->count().' ID cards and passports disagree',
            'detail' => $off->map(fn ($bp) => $bp->pilgrim->name_en)->implode(', ').'. Correct a reading or explain the difference on the pilgrim.',
            'count' => $off->count(), 'url' => null,
        ]];
    }

    /** @param  Collection<int, BookingPilgrim>  $travellers */
    private function visas(Collection $travellers): array
    {
        $notIssued = $travellers->filter(fn (BookingPilgrim $bp): bool => $bp->visa_status !== BookingPilgrim::VISA_ISSUED);
        $late = $travellers->filter(fn (BookingPilgrim $bp): bool => $bp->visaIsLate());
        $out = [];

        if ($notIssued->isNotEmpty()) {
            $byStatus = $notIssued->groupBy('visa_status')->map(fn ($g, $s) => $g->count().' '.strtolower(BookingPilgrim::VISA_STATUSES[$s] ?? $s))->implode(', ');
            $out[] = ['area' => 'visas', 'severity' => self::BLOCKING, 'headline' => $notIssued->count() === 1 ? 'One visa not issued' : $notIssued->count().' visas not issued', 'detail' => ucfirst($byStatus).'.', 'count' => $notIssued->count(), 'url' => null];
        }

        if ($late->isNotEmpty()) {
            $out[] = ['area' => 'visas', 'severity' => self::ATTENTION, 'headline' => $late->count() === 1 ? 'One visa is late back from the agent' : $late->count().' visas are late back from the agent', 'detail' => 'Submitted more than '.config('booking.visa_sla_days', 7).' days ago: '.$late->map(fn ($bp) => $bp->pilgrim->name_en)->implode(', ').'.', 'count' => $late->count(), 'url' => null];
        }

        return $out;
    }

    /** @param  Collection<int, BookingPilgrim>  $travellers */
    private function tickets(Trip $trip, Collection $travellers): array
    {
        if (! $trip->flights()->exists()) {
            return [];
        }

        $none = $travellers->filter(fn (BookingPilgrim $bp): bool => blank($bp->ticket_number));

        return $none->isEmpty() ? [] : [[
            'area' => 'tickets', 'severity' => self::ATTENTION,
            'headline' => $none->count() === 1 ? 'One traveller has no ticket number' : $none->count().' travellers have no ticket number',
            'detail' => $none->map(fn ($bp) => $bp->pilgrim->name_en)->implode(', ').'.',
            'count' => $none->count(), 'url' => null,
        ]];
    }

    private function money(Trip $trip): array
    {
        $due = $trip->bookings()->where('status', Booking::CONFIRMED)->with('customer')->get()->reject(fn (Booking $b): bool => $b->isPaidUp());

        return $due->isEmpty() ? [] : [[
            'area' => 'money', 'severity' => self::ATTENTION,
            'headline' => $due->count() === 1 ? 'One booking still owes money' : $due->count().' bookings still owe money',
            'detail' => $due->map(fn (Booking $b) => $b->customer->name.': '.$b->balanceSummary())->implode('; ').'.',
            'count' => $due->count(), 'url' => route('filament.portal.resources.bookings.index', ['activeTab' => 'unpaid']),
        ]];
    }

    /** @param  Collection<int, BookingPilgrim>  $travellers */
    private function buses(Trip $trip, Collection $travellers): array
    {
        $problems = $this->buses->problemsWith($trip);

        if ($problems === []) {
            return [];
        }

        return [[
            'area' => 'buses', 'severity' => self::ATTENTION,
            'headline' => count($problems) === 1 ? 'One thing wrong with the buses' : count($problems).' things wrong with the buses',
            'detail' => implode(' ', array_column($problems, 'detail')),
            'count' => count($problems), 'url' => null,
        ]];
    }

    private function rooms(Trip $trip): array
    {
        $out = [];

        foreach ($trip->hotels as $hotel) {
            $problems = $this->rooming->problemsWith($hotel);

            if ($problems === []) {
                continue;
            }

            $out[] = [
                'area' => 'rooms', 'severity' => self::ATTENTION,
                'headline' => $hotel->name.': '.(count($problems) === 1 ? 'one rooming problem' : count($problems).' rooming problems'),
                'detail' => implode(' ', array_map(fn (array $p): string => ($p['room'] ? $p['room'].': ' : '').$p['detail'], array_slice($problems, 0, 6))).(count($problems) > 6 ? ' …' : ''),
                'count' => count($problems), 'url' => null,
            ];
        }

        return $out;
    }

    private function checklist(Trip $trip): array
    {
        $open = $trip->checklist()->open()->get();
        $overdue = $open->filter(fn (TripChecklistItem $i): bool => $i->isOverdue());
        $blocking = $open->filter(fn (TripChecklistItem $i): bool => (bool) ($i->is_blocking ?? false));
        $out = [];

        if ($blocking->isNotEmpty()) {
            $out[] = ['area' => 'checklist', 'severity' => self::BLOCKING, 'headline' => $blocking->count() === 1 ? 'One blocking checklist item open' : $blocking->count().' blocking checklist items open', 'detail' => $blocking->pluck('title')->implode(', ').'.', 'count' => $blocking->count(), 'url' => null];
        }

        $late = $overdue->reject(fn ($i) => $blocking->contains($i));

        if ($late->isNotEmpty()) {
            $out[] = ['area' => 'checklist', 'severity' => self::ATTENTION, 'headline' => $late->count() === 1 ? 'One checklist item overdue' : $late->count().' checklist items overdue', 'detail' => $late->pluck('title')->implode(', ').'.', 'count' => $late->count(), 'url' => null];
        }

        return $out;
    }
}
