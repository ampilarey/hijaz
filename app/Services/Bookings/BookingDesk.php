<?php

namespace App\Services\Bookings;

use App\Models\Booking;
use App\Models\BookingLine;
use App\Models\BookingPilgrim;
use App\Models\Customer;
use App\Models\Discount;
use App\Models\Pilgrim;
use App\Models\PriceTier;
use App\Models\SeatHold;
use App\Models\Trip;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Making and changing a booking: pilgrims on seats at the trip's prices,
 * the private-room upgrade in USD, extras, fees and discounts as lines,
 * confirming, cancelling. Seats go through the allocator.
 */
final class BookingDesk
{
    public function __construct(private readonly SeatAllocator $seats) {}

    /**
     * @param  list<array{pilgrim_id: int, occupancy: string}>  $party
     */
    public function open(Trip $trip, Customer $customer, array $party, ?string $notes = null): Booking
    {
        if ($party === []) {
            throw new \InvalidArgumentException('A booking needs at least one pilgrim.');
        }

        return DB::transaction(function () use ($trip, $customer, $party, $notes): Booking {
            $booking = Booking::query()->create(['trip_id' => $trip->getKey(), 'customer_id' => $customer->getKey(), 'notes' => $notes]);
            $booking->forceFill(['trip_snapshot' => $this->snapshot($trip)])->save();

            $this->seats->hold($trip, count($party), $booking);

            foreach ($party as $i => $member) {
                $this->addPilgrim($booking, Pilgrim::query()->findOrFail($member['pilgrim_id']), $member['occupancy'], isLead: $i === 0, resizeHold: false);
            }

            $booking->forceFill(['seats' => count($party)])->save();

            return $booking->fresh(['pilgrims', 'lines']);
        });
    }

    /** Price a pilgrim's seat from the trip's tiers and write the lines. */
    public function addPilgrim(Booking $booking, Pilgrim $pilgrim, string $occupancy, bool $isLead = false, bool $resizeHold = true): BookingPilgrim
    {
        return DB::transaction(function () use ($booking, $pilgrim, $occupancy, $isLead, $resizeHold): BookingPilgrim {
            if ($booking->status !== Booking::DRAFT && $booking->status !== Booking::CONFIRMED) {
                throw new \InvalidArgumentException('Pilgrims can only be added to a draft or confirmed booking.');
            }

            if ($resizeHold) {
                $hold = $this->liveHold($booking);

                if ($hold) {
                    $this->seats->resize($hold, $hold->seats + 1);
                } else {
                    $this->seats->hold($booking->trip, 1, $booking);
                    $this->seats->confirm($booking->seatHolds()->latest('id')->firstOrFail());
                }
            }

            $trip = $booking->trip;
            $paxType = $pilgrim->paxTypeOn($trip->departs_on);
            $tier = $this->tierFor($trip, $occupancy, $paxType);

            $bp = $booking->pilgrims()->create([
                'pilgrim_id' => $pilgrim->getKey(),
                'occupancy' => $occupancy,
                'pax_type' => $paxType,
                'price_tier_id' => $tier?->getKey(),
                'amount_minor' => $tier?->amount_minor ?? 0,
                'currency' => $tier?->currency ?? 'MVR',
                'is_lead' => $isLead,
            ]);

            if ($tier !== null) {
                $this->line($booking, $bp, $occupancy === PriceTier::SINGLE ? BookingLine::ROOM_UPGRADE : BookingLine::SEAT,
                    $pilgrim->name_en.' · '.$tier->label(), $tier->amount_minor, $tier->currency);
            }

            $booking->forceFill(['seats' => $booking->pilgrims()->count()])->save();

            return $bp;
        });
    }

    public function removePilgrim(BookingPilgrim $bp): void
    {
        DB::transaction(function () use ($bp): void {
            $booking = $bp->booking;
            $bp->lines()->delete();
            $bp->delete();

            $hold = $this->liveHold($booking);

            if ($hold && $hold->seats > 1) {
                $this->seats->resize($hold, $hold->seats - 1);
            }

            $booking->forceFill(['seats' => $booking->pilgrims()->count()])->save();
            $booking->recalculateTotals();
        });
    }

    public function addLine(Booking $booking, string $type, string $description, Money $unit, int $quantity = 1, ?BookingPilgrim $pilgrim = null, ?int $excursionId = null): BookingLine
    {
        return $this->line($booking, $pilgrim, $type, $description, $unit->minor, $unit->currency, $quantity, $excursionId);
    }

    /** A discount is a negative line with a reason and an approver. */
    public function discount(Booking $booking, string $kind, string $value, string $currency, string $reason): Discount
    {
        return DB::transaction(function () use ($booking, $kind, $value, $currency, $reason): Discount {
            $total = $booking->total($currency)->minor;

            $minor = $kind === Discount::PERCENT
                ? (int) round($total * ((float) $value) / 100)
                : Money::ofMajor($value, $currency)->minor;

            if ($minor <= 0) {
                throw new \InvalidArgumentException('A discount must be worth something.');
            }

            if ($minor > $total) {
                throw new \InvalidArgumentException('A discount cannot be more than the '.Money::ofMinor($total, $currency)->format().' due in that currency.');
            }

            $line = $this->line($booking, null, BookingLine::DISCOUNT, 'Discount: '.$reason, -$minor, $currency);

            return $booking->discounts()->create([
                'booking_line_id' => $line->getKey(),
                'kind' => $kind,
                'value' => $value,
                'currency' => $currency,
                'amount_minor' => -$minor,
                'reason' => $reason,
                'approved_by' => Auth::id(),
                'approved_at' => now(),
            ]);
        });
    }

    public function reverseDiscount(Discount $discount): void
    {
        DB::transaction(function () use ($discount): void {
            $discount->line?->delete();
            $discount->forceFill(['reversed_at' => now()])->save();
            $discount->booking->recalculateTotals();
        });
    }

    public function confirm(Booking $booking): void
    {
        DB::transaction(function () use ($booking): void {
            $booking->transitionTo(Booking::CONFIRMED);

            $hold = $booking->seatHolds()->whereNull('confirmed_at')->latest('id')->first();

            if ($hold instanceof SeatHold) {
                $this->seats->confirm($hold);
            }
        });
    }

    public function cancel(Booking $booking, string $reason): void
    {
        DB::transaction(function () use ($booking, $reason): void {
            $wasConfirmed = $booking->status === Booking::CONFIRMED;
            $booking->transitionTo(Booking::CANCELLED, $reason);

            if ($wasConfirmed) {
                $this->seats->releaseConfirmed($booking);
            } else {
                foreach ($booking->seatHolds()->whereNull('released_at')->get() as $hold) {
                    $this->seats->release($hold, SeatHold::CANCELLED);
                }
            }
        });
    }

    public function complete(Booking $booking): void
    {
        $booking->transitionTo(Booking::COMPLETED);
    }

    public function extendHold(Booking $booking, int $days): void
    {
        $hold = $booking->seatHolds()->whereNull('confirmed_at')->latest('id')->first();

        if ($hold instanceof SeatHold) {
            $this->seats->extend($hold, now()->addDays($days));
        }
    }

    public function tierFor(Trip $trip, string $occupancy, string $paxType): ?PriceTier
    {
        return $trip->priceTiers->first(fn (PriceTier $t): bool => $t->occupancy === $occupancy && $t->pax_type === $paxType)
            ?? $trip->priceTiers->first(fn (PriceTier $t): bool => $t->occupancy === $occupancy && $t->pax_type === PriceTier::ADULT);
    }

    private function liveHold(Booking $booking): ?SeatHold
    {
        return $booking->seatHolds()->whereNull('released_at')->where('expires_at', '>', now())->latest('id')->first();
    }

    private function line(Booking $booking, ?BookingPilgrim $bp, string $type, string $description, int $unitMinor, string $currency, int $quantity = 1, ?int $excursionId = null): BookingLine
    {
        return $booking->lines()->create([
            'booking_pilgrim_id' => $bp?->getKey(),
            'type' => $type,
            'description' => mb_substr($description, 0, 160),
            'quantity' => $quantity,
            'unit_amount_minor' => $unitMinor,
            'amount_minor' => $unitMinor * $quantity,
            'currency' => $currency,
            'trip_excursion_id' => $excursionId,
            'sort_order' => (int) $booking->lines()->max('sort_order') + 1,
        ]);
    }

    /** @return array<string, mixed> */
    private function snapshot(Trip $trip): array
    {
        return [
            'version' => 1,
            'trip' => ['code' => $trip->code, 'name' => $trip->name, 'departs_on' => $trip->departs_on->toDateString(), 'returns_on' => $trip->returns_on->toDateString()],
            'prices' => $trip->priceTiers->map(fn (PriceTier $t): array => $t->only(['occupancy', 'pax_type', 'amount_minor', 'currency']))->all(),
            'hotels' => $trip->hotels->map(fn ($h): array => $h->only(['city', 'name', 'check_in_on', 'check_out_on']))->all(),
        ];
    }
}
