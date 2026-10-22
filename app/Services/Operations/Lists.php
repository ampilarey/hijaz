<?php

namespace App\Services\Operations;

use App\Models\Booking;
use App\Models\BookingPilgrim;
use App\Models\BusGroup;
use App\Models\Document;
use App\Models\ExcursionBooking;
use App\Models\Pilgrim;
use App\Models\Trip;
use App\Models\TripExcursion;
use App\Models\TripGroup;
use App\Models\TripHotel;
use App\Services\Invoices\Documents;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;
use Illuminate\Support\Collection;

/**
 * The lists a trip runs on: the manifest in the shape each reader wants,
 * one list per bus, one per hotel, and who still owes what. Built from the
 * same rows, printed as PDF or exported as CSV.
 */
final class Lists
{
    /** @var array<string, string> */
    public const PRESETS = [
        'office' => 'Office list (everything)',
        'airline' => 'Airline list (passport details and tickets)',
        'hotel' => 'Hotel list (names, passports, rooms)',
        'ministry' => 'Ministry list (ID, passport, island)',
    ];

    public function __construct(private readonly Documents $documents) {}

    public function manifest(Trip $trip, string $preset = 'office', ?Collection $only = null, ?TripGroup $group = null): ListDocument
    {
        $travellers = $only ?? $this->travellers($trip);

        if ($group !== null) {
            $travellers = $travellers->where('trip_group_id', $group->getKey());
        }
        $hotels = $trip->hotels()->orderBy('check_in_on')->get();

        $columns = match ($preset) {
            'airline' => ['n' => '#', 'name' => 'Name as on passport', 'passport' => 'Passport no', 'nationality' => 'Nat.', 'dob' => 'Date of birth', 'gender' => 'Sex', 'expiry' => 'Expiry', 'group' => 'Group', 'flight' => 'Flight', 'ticket' => 'Ticket', 'seat' => 'Seat'],
            'hotel' => ['n' => '#', 'name' => 'Name', 'gender' => 'Sex', 'passport' => 'Passport no', 'nationality' => 'Nat.', ...$hotels->mapWithKeys(fn (TripHotel $h) => ['room_'.$h->id => $h->cityLabel().' room'])->all()],
            'ministry' => ['n' => '#', 'name' => 'Name', 'id_number' => 'ID number', 'passport' => 'Passport no', 'dob' => 'Date of birth', 'gender' => 'Sex', 'island' => 'Island', 'phone' => 'Phone'],
            default => ['n' => '#', 'name' => 'Name', 'id_number' => 'ID no', 'passport' => 'Passport', 'expiry' => 'Expiry', 'dob' => 'DOB', 'gender' => 'Sex', 'phone' => 'Phone', 'family' => 'Family', 'booking' => 'Booking', 'group' => 'Group', 'bus' => 'Bus', ...$hotels->mapWithKeys(fn (TripHotel $h) => ['room_'.$h->id => $h->cityLabel()])->all(), 'flight' => 'Flights', 'visa' => 'Visa', 'ticket' => 'Ticket', 'paid' => 'Paid'],
        };

        $rows = [];

        foreach ($travellers->values() as $i => $bp) {
            $rows[] = $this->row($bp, $i + 1, $hotels);
        }

        return new ListDocument(
            title: (self::PRESETS[$preset] ?? 'List'),
            subtitle: $trip->name.' · '.$trip->code.' · '.($group ? $group->name.' · '.$group->flightsLabel() : $trip->datesLabel()).' · '.count($rows).' travellers',
            columns: $columns,
            rows: $rows,
            landscape: count($columns) > 7,
            notes: $preset === 'office' ? ['Draft bookings are marked *. Paid shows the balance due in each currency.'] : [],
            filename: strtolower($trip->code).($group ? '-'.str($group->name)->slug() : '').'-'.$preset.'-list',
        );
    }

    public function busList(BusGroup $bus): ListDocument
    {
        $trip = $bus->trip;
        $hotels = $trip->hotels()->orderBy('check_in_on')->get();
        $members = $bus->members()->with(['pilgrim', 'booking.customer', 'roomAssignments.room'])->get()
            ->sortBy(fn (BookingPilgrim $bp) => $bp->booking->customer->name.' '.$bp->pilgrim->name_en)->values();

        $rows = [];

        foreach ($members as $i => $bp) {
            $rows[] = $this->row($bp, $i + 1, $hotels) + ['tick' => $bp->isCheckedIn() ? '☑' : '☐', 'needs' => $this->needs($bp)];
        }

        return new ListDocument(
            title: $bus->name,
            subtitle: $trip->name.' · '.$trip->code.' · leader '.($bus->leaderLabel() ?? '—').($bus->leader_phone ? ' '.$bus->leader_phone : '').' · '.count($rows).' on board'.($bus->capacity ? ' of '.$bus->capacity : ''),
            columns: ['tick' => '✓', 'n' => '#', 'name' => 'Name', 'id_number' => 'ID no', 'phone' => 'Phone', 'family' => 'Family', ...$hotels->mapWithKeys(fn (TripHotel $h) => ['room_'.$h->id => $h->cityLabel()])->all(), 'needs' => 'Needs / emergency'],
            rows: $rows,
            landscape: true,
            notes: ['Tick the box as each person boards.'],
            filename: strtolower($trip->code).'-'.str_replace(' ', '-', strtolower($bus->name)),
        );
    }

    /** The hotel's copy has no phone numbers and no ID numbers; the office copy has both. */
    public function roomList(TripHotel $hotel, bool $forHotel = false): ListDocument
    {
        $rows = [];

        foreach ($hotel->rooms()->with('assignments.bookingPilgrim.pilgrim', 'assignments.bookingPilgrim.booking.customer')->get() as $room) {
            $occupants = $room->occupants();

            if ($occupants->isEmpty()) {
                $rows[] = ['room' => $room->label, 'beds' => $room->capacity, 'type' => $room->genderLabel(), 'name' => '(empty)', 'passport' => '', 'nationality' => '', 'phone' => '', 'family' => ''];

                continue;
            }

            foreach ($occupants as $bp) {
                $rows[] = [
                    'room' => $room->label,
                    'beds' => $room->capacity,
                    'type' => $room->genderLabel(),
                    'name' => $bp->pilgrim->name_en,
                    'passport' => (string) $bp->pilgrim->passport_number,
                    'nationality' => $bp->pilgrim->nationality,
                    'phone' => (string) $bp->pilgrim->phone,
                    'family' => $bp->booking->customer->name,
                ];
            }
        }

        $columns = $forHotel
            ? ['room' => 'Room', 'beds' => 'Beds', 'type' => 'Type', 'name' => 'Guest', 'passport' => 'Passport no', 'nationality' => 'Nat.']
            : ['room' => 'Room', 'beds' => 'Beds', 'type' => 'Type', 'name' => 'Guest', 'passport' => 'Passport no', 'family' => 'Family', 'phone' => 'Phone'];

        return new ListDocument(
            title: $hotel->name.' rooming list'.($forHotel ? ' (hotel copy)' : ''),
            subtitle: $hotel->cityLabel().' · '.$hotel->check_in_on->format('j M').' – '.$hotel->check_out_on->format('j M Y').' · '.$hotel->trip->name.' · '.$hotel->trip->code.($hotel->booking_reference ? ' · ref '.$hotel->booking_reference : ''),
            columns: $columns,
            rows: $rows,
            landscape: false,
            filename: strtolower($hotel->trip->code).'-'.$hotel->city.'-rooms'.($forHotel ? '-hotel' : ''),
        );
    }

    public function payments(Trip $trip): ListDocument
    {
        $rows = [];

        foreach ($trip->bookings()->live()->with(['customer', 'pilgrims'])->orderBy('id')->get() as $i => $b) {
            $rows[] = [
                'n' => $i + 1,
                'booking' => $b->reference,
                'customer' => $b->customer->name,
                'phone' => (string) $b->customer->phone,
                'people' => $b->pilgrims->count(),
                'status' => $b->statusLabel(),
                'total' => implode(' · ', array_map(fn (string $c) => $b->total($c)->format(), $b->currencies())),
                'paid' => implode(' · ', array_map(fn (string $c) => $b->paidIn($c)->format(), $b->currencies())),
                'balance' => $b->balanceSummary(),
            ];
        }

        return new ListDocument(
            title: 'Payment status',
            subtitle: $trip->name.' · '.$trip->code.' · '.count($rows).' bookings',
            columns: ['n' => '#', 'booking' => 'Booking', 'customer' => 'Customer', 'phone' => 'Phone', 'people' => 'People', 'status' => 'Status', 'total' => 'Total', 'paid' => 'Received', 'balance' => 'Balance'],
            rows: $rows,
            landscape: true,
            filename: strtolower($trip->code).'-payments',
        );
    }

    public function documents(Trip $trip): ListDocument
    {
        $rows = [];

        foreach ($this->travellers($trip)->values() as $i => $bp) {
            $p = $bp->pilgrim;
            $state = fn (string $type): string => match (true) {
                ($d = $p->document($type)) === null || $d->currentVersion() === null => 'missing',
                $d->isVerified() => 'verified',
                $d->status === Document::REJECTED => 'rejected',
                default => 'to check',
            };

            $rows[] = [
                'n' => $i + 1,
                'name' => $p->name_en,
                'phone' => (string) $p->phone,
                'id_card' => $state(Document::ID_CARD),
                'passport' => $state(Document::PASSPORT),
                'expiry' => $p->passport_expires_on?->format('d/m/Y') ?? '',
                'photo' => $state(Document::PHOTO),
                'visa' => $bp->visaLabel(),
                'registration' => ucfirst($p->registration_status),
            ];
        }

        return new ListDocument(
            title: 'Documents still owed',
            subtitle: $trip->name.' · '.$trip->code,
            columns: ['n' => '#', 'name' => 'Name', 'phone' => 'Phone', 'id_card' => 'ID card', 'passport' => 'Passport', 'expiry' => 'Expiry', 'photo' => 'Photo', 'visa' => 'Visa', 'registration' => 'Registration'],
            rows: $rows,
            landscape: true,
            filename: strtolower($trip->code).'-documents',
        );
    }

    public function excursion(TripExcursion $excursion): ListDocument
    {
        $rows = [];
        $n = 0;

        foreach (ExcursionBooking::query()->where('trip_excursion_id', $excursion->getKey())->where('status', '!=', ExcursionBooking::CANCELLED)->with(['customer', 'participants.bookingPilgrim.pilgrim', 'participants.bookingPilgrim.busGroup'])->get() as $xb) {
            foreach ($xb->participants as $p) {
                $rows[] = [
                    'n' => ++$n,
                    'name' => $p->bookingPilgrim?->pilgrim?->name_en ?? '',
                    'phone' => (string) ($p->bookingPilgrim?->pilgrim?->phone ?? ''),
                    'family' => $xb->customer->name,
                    'bus' => $p->bookingPilgrim?->busGroup?->name ?? '',
                    'paid' => $xb->status === ExcursionBooking::PAID ? 'paid' : $xb->balance()->format().' due',
                    'reference' => $xb->reference,
                ];
            }
        }

        return new ListDocument(
            title: $excursion->title,
            subtitle: $excursion->trip->name.' · '.$excursion->trip->code.($excursion->scheduled_on ? ' · '.$excursion->scheduled_on->format('j M Y') : '').' · '.$n.' going',
            columns: ['n' => '#', 'name' => 'Name', 'phone' => 'Phone', 'family' => 'Family', 'bus' => 'Bus', 'paid' => 'Paid', 'reference' => 'Ref'],
            rows: $rows,
            filename: strtolower($excursion->trip->code).'-'.str_replace(' ', '-', strtolower($excursion->title)),
        );
    }

    public function pdf(ListDocument $list): PdfDocument
    {
        return Pdf::loadView('pdf.list', ['list' => $list, 'issuer' => $this->documents->issuer()])
            ->setPaper('a4', $list->landscape ? 'landscape' : 'portrait');
    }

    /** @return Collection<int, BookingPilgrim> */
    public function travellers(Trip $trip): Collection
    {
        return BookingPilgrim::query()
            ->whereIn('booking_id', Booking::query()->where('trip_id', $trip->getKey())->live()->select('id'))
            ->with(['pilgrim.island', 'pilgrim.documents.versions', 'booking.customer', 'booking.trip.flights', 'busGroup', 'roomAssignments.room', 'outboundFlight', 'returnFlight', 'group.outboundFlight', 'group.returnFlight'])
            ->get()
            ->sortBy(fn (BookingPilgrim $bp) => ($bp->booking->status === Booking::CONFIRMED ? '0' : '1').$bp->booking->customer->name.' '.$bp->pilgrim->name_en)
            ->values();
    }

    /**
     * @param  Collection<int, TripHotel>  $hotels
     * @return array<string, string|int|null>
     */
    private function row(BookingPilgrim $bp, int $n, Collection $hotels): array
    {
        $p = $bp->pilgrim;
        $b = $bp->booking;

        $row = [
            'n' => $n,
            'name' => $p->name_en.($b->status === Booking::DRAFT ? ' *' : ''),
            'id_number' => (string) $p->id_number,
            'passport' => (string) $p->passport_number,
            'nationality' => $p->nationality,
            'expiry' => $p->passport_expires_on?->format('d/m/Y') ?? '',
            'dob' => $p->date_of_birth?->format('d/m/Y') ?? '',
            'gender' => $p->gender === null ? '' : strtoupper(substr($p->gender, 0, 1)),
            'phone' => (string) $p->phone,
            'island' => $p->island?->label ?? '',
            'family' => $b->customer->name,
            'booking' => $b->reference,
            'group' => $bp->group?->name ?? '',
            'bus' => $bp->busGroup?->name ?? '',
            'visa' => $bp->visaLabel(),
            'flight' => $bp->flightsLabel(),
            'ticket' => (string) $bp->ticket_number,
            'seat' => (string) $bp->seat_number,
            'paid' => $b->isPaidUp() && $b->currencies() !== [] ? 'paid' : $b->balanceSummary(),
        ];

        foreach ($hotels as $hotel) {
            $row['room_'.$hotel->id] = $bp->roomIn($hotel)?->label ?? '';
        }

        return $row;
    }

    private function needs(BookingPilgrim $bp): string
    {
        $p = $bp->pilgrim;
        $parts = array_map(fn (string $k) => Pilgrim::NEEDS[$k] ?? $k, array_keys(array_filter($p->needs ?? [])));

        if ($p->emergency_contact_phone) {
            $parts[] = 'Emergency: '.($p->emergency_contact_name ? $p->emergency_contact_name.' ' : '').$p->emergency_contact_phone;
        }

        return implode('; ', $parts);
    }
}
