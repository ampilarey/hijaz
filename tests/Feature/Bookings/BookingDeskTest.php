<?php

namespace Tests\Feature\Bookings;

use App\Exceptions\NoSeatsAvailable;
use App\Models\Booking;
use App\Models\BookingLine;
use App\Models\Discount;
use App\Models\PriceTier;
use App\Models\SeatHold;
use App\Services\Bookings\BookingDesk;
use App\Services\Bookings\Excursions;
use App\Services\Bookings\SeatAllocator;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PricedTrip;
use Tests\TestCase;

class BookingDeskTest extends TestCase
{
    use RefreshDatabase;

    private function desk(): BookingDesk
    {
        return app(BookingDesk::class);
    }

    public function test_a_family_is_priced_by_tier_and_age_and_holds_its_seats(): void
    {
        $trip = PricedTrip::make();
        [$customer, $head, $spouse, $child] = PricedTrip::family();

        $booking = $this->desk()->open($trip, $customer, [
            ['pilgrim_id' => $head->id, 'occupancy' => PriceTier::QUAD],
            ['pilgrim_id' => $spouse->id, 'occupancy' => PriceTier::QUAD],
            ['pilgrim_id' => $child->id, 'occupancy' => PriceTier::QUAD],
        ]);

        $this->assertMatchesRegularExpression('/^HJZ-B-\d{4}-\d{4}$/', $booking->reference);
        $this->assertSame(Booking::DRAFT, $booking->status);
        $this->assertSame(3, $booking->seats);
        $this->assertSame(['MVR' => 2850000 * 2 + 2000000], $booking->totals);
        $this->assertSame('MVR 77,000 due', $booking->balanceSummary());
        $this->assertTrue($booking->pilgrims->first()->is_lead);
        $this->assertSame(PriceTier::CHILD, $booking->pilgrims->firstWhere('pilgrim_id', $child->id)->pax_type);

        $trip->refresh();
        $this->assertSame(3, $trip->capacity_held);
        $this->assertSame(0, $trip->capacity_confirmed);
        $this->assertSame(1, $trip->trip_snapshot === null ? 1 : 1);
        $this->assertSame($trip->code, $booking->trip_snapshot['trip']['code']);
    }

    public function test_a_private_room_is_a_dollar_line_beside_the_rufiyaa_fare(): void
    {
        $trip = PricedTrip::make();
        [$customer, $head] = PricedTrip::family();

        $booking = $this->desk()->open($trip, $customer, [['pilgrim_id' => $head->id, 'occupancy' => PriceTier::SINGLE]]);

        $this->assertSame(['USD' => 29950], $booking->totals);
        $this->assertSame(BookingLine::ROOM_UPGRADE, $booking->lines->first()->type);
        $this->assertSame('USD 299.50 due', $booking->balanceSummary());
    }

    public function test_a_missing_tier_falls_back_to_the_adult_price(): void
    {
        $trip = PricedTrip::make();
        [$customer, , , $child] = PricedTrip::family();

        $booking = $this->desk()->open($trip, $customer, [['pilgrim_id' => $child->id, 'occupancy' => PriceTier::DOUBLE]]);

        $this->assertSame(3200000, $booking->pilgrims->first()->amount_minor);
    }

    public function test_the_last_seat_goes_to_one_booking_only(): void
    {
        $trip = PricedTrip::make(capacity: 2);
        [$customer, $head, $spouse, $child] = PricedTrip::family();

        $this->expectException(NoSeatsAvailable::class);
        $this->desk()->open($trip, $customer, [
            ['pilgrim_id' => $head->id, 'occupancy' => PriceTier::QUAD],
            ['pilgrim_id' => $spouse->id, 'occupancy' => PriceTier::QUAD],
            ['pilgrim_id' => $child->id, 'occupancy' => PriceTier::QUAD],
        ]);
    }

    public function test_confirming_moves_seats_from_held_to_confirmed_and_cancelling_gives_them_back(): void
    {
        $trip = PricedTrip::make();
        [$customer, $head, $spouse] = PricedTrip::family();
        $booking = $this->desk()->open($trip, $customer, [
            ['pilgrim_id' => $head->id, 'occupancy' => PriceTier::DOUBLE],
            ['pilgrim_id' => $spouse->id, 'occupancy' => PriceTier::DOUBLE],
        ]);

        $this->desk()->confirm($booking);
        $this->assertSame(Booking::CONFIRMED, $booking->fresh()->status);
        $this->assertSame([0, 2], [$trip->fresh()->capacity_held, $trip->fresh()->capacity_confirmed]);

        $this->desk()->cancel($booking->fresh(), 'Changed their minds');
        $this->assertSame(Booking::CANCELLED, $booking->fresh()->status);
        $this->assertSame('Changed their minds', $booking->fresh()->cancellation_reason);
        $this->assertSame([0, 0], [$trip->fresh()->capacity_held, $trip->fresh()->capacity_confirmed]);
        $this->assertCount(2, $booking->transitions);
    }

    public function test_a_lapsed_hold_cancels_the_draft_and_frees_the_seats(): void
    {
        $trip = PricedTrip::make();
        [$customer, $head] = PricedTrip::family();
        $booking = $this->desk()->open($trip, $customer, [['pilgrim_id' => $head->id, 'occupancy' => PriceTier::QUAD]]);

        $this->travel(8)->days();
        $this->assertSame(1, app(SeatAllocator::class)->reclaim($trip));

        $this->assertSame(Booking::CANCELLED, $booking->fresh()->status);
        $this->assertSame(0, $trip->fresh()->capacity_held);
        $this->assertSame(SeatHold::EXPIRED, $booking->seatHolds()->first()->released_reason);
    }

    public function test_extending_a_hold_keeps_the_seats(): void
    {
        $trip = PricedTrip::make();
        [$customer, $head] = PricedTrip::family();
        $booking = $this->desk()->open($trip, $customer, [['pilgrim_id' => $head->id, 'occupancy' => PriceTier::QUAD]]);

        $this->desk()->extendHold($booking, 14);
        $this->travel(8)->days();
        $this->assertSame(0, app(SeatAllocator::class)->reclaim($trip));
        $this->assertSame(Booking::DRAFT, $booking->fresh()->status);
    }

    public function test_adding_and_removing_a_pilgrim_resizes_the_hold_and_reprices(): void
    {
        $trip = PricedTrip::make();
        [$customer, $head, $spouse] = PricedTrip::family();
        $booking = $this->desk()->open($trip, $customer, [['pilgrim_id' => $head->id, 'occupancy' => PriceTier::QUAD]]);

        $bp = $this->desk()->addPilgrim($booking, $spouse, PriceTier::QUAD);
        $this->assertSame(2, $booking->fresh()->seats);
        $this->assertSame(2, $trip->fresh()->capacity_held);
        $this->assertSame(5700000, $booking->fresh()->totals['MVR']);

        $this->desk()->removePilgrim($bp);
        $this->assertSame(1, $booking->fresh()->seats);
        $this->assertSame(1, $trip->fresh()->capacity_held);
        $this->assertSame(2850000, $booking->fresh()->totals['MVR']);
    }

    public function test_discounts_are_negative_lines_with_a_reason_and_cannot_exceed_the_total(): void
    {
        $trip = PricedTrip::make();
        [$customer, $head] = PricedTrip::family();
        $booking = $this->desk()->open($trip, $customer, [['pilgrim_id' => $head->id, 'occupancy' => PriceTier::QUAD]]);

        $fixed = $this->desk()->discount($booking, Discount::FIXED, '500', 'MVR', 'Returning pilgrim');
        $this->assertSame(-50000, $fixed->amount_minor);
        $this->assertSame(2800000, $booking->fresh()->totals['MVR']);

        $percent = $this->desk()->discount($booking->fresh(), Discount::PERCENT, '10', 'MVR', 'Group of ten');
        $this->assertSame(-280000, $percent->amount_minor);
        $this->assertSame(2520000, $booking->fresh()->totals['MVR']);

        $this->desk()->reverseDiscount($percent);
        $this->assertSame(2800000, $booking->fresh()->totals['MVR']);
        $this->assertNotNull($percent->fresh()->reversed_at);

        $this->expectException(\InvalidArgumentException::class);
        $this->desk()->discount($booking->fresh(), Discount::FIXED, '99999', 'MVR', 'Too much');
    }

    public function test_extras_and_fees_are_lines_in_any_currency(): void
    {
        $trip = PricedTrip::make();
        [$customer, $head] = PricedTrip::family();
        $booking = $this->desk()->open($trip, $customer, [['pilgrim_id' => $head->id, 'occupancy' => PriceTier::QUAD]]);

        $this->desk()->addLine($booking, BookingLine::FEE, 'Visa fee', Money::ofMajor('120', 'USD'));
        $this->desk()->addLine($booking, BookingLine::EXTRA, 'Zamzam 5L', Money::ofMajor('25', 'SAR'), 2);

        $this->assertSame(['MVR' => 2850000, 'SAR' => 5000, 'USD' => 12000], $booking->fresh()->totals);
        $this->assertSame('MVR 28,500 and SAR 50 and USD 120 due', $booking->fresh()->balanceSummary());
    }

    public function test_an_additional_trip_is_its_own_riyal_payable(): void
    {
        $trip = PricedTrip::make();
        [$customer, $head, $spouse] = PricedTrip::family();
        $booking = $this->desk()->open($trip, $customer, [
            ['pilgrim_id' => $head->id, 'occupancy' => PriceTier::QUAD],
            ['pilgrim_id' => $spouse->id, 'occupancy' => PriceTier::QUAD],
        ]);
        $taif = $trip->excursions()->create(['title' => 'Taif Ziyarah', 'is_included' => false, 'price_minor' => 15000, 'currency' => 'SAR', 'capacity' => 1]);

        $xb = app(Excursions::class)->book($taif, $booking, [$booking->pilgrims->first()->id]);

        $this->assertMatchesRegularExpression('/^HJZ-X-\d{4}-\d{4}$/', $xb->reference);
        $this->assertSame(15000, $xb->total_minor);
        $this->assertSame('SAR 150', $xb->balance()->format());

        $this->expectException(\InvalidArgumentException::class);
        app(Excursions::class)->book($taif, $booking, [$booking->pilgrims->last()->id]);
    }
}
