<?php

namespace Tests\Feature\Trips;

use App\Exceptions\IllegalTripTransition;
use App\Models\Trip;
use App\Models\TripHotel;
use App\Models\User;
use App\Services\Trips\RouteBuilder;
use App\Services\Trips\TripCopier;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TripModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_trip_gets_a_code_from_its_departure_month(): void
    {
        $first = Trip::factory()->create(['departs_on' => '2027-07-10', 'returns_on' => '2027-07-24']);
        $second = Trip::factory()->create(['departs_on' => '2027-07-20', 'returns_on' => '2027-08-03']);

        $this->assertSame('UMR-2027-07', $first->code);
        $this->assertSame('UMR-2027-07-2', $second->code);
        $this->assertSame(14, $first->nights());
    }

    public function test_status_moves_only_along_the_allowed_paths_and_is_recorded(): void
    {
        $this->actingAs(User::factory()->create(['name' => 'Ops']));
        $trip = Trip::factory()->create();

        $this->assertSame(Trip::DRAFT, $trip->status);
        $trip->transitionTo(Trip::OPEN);
        $trip->transitionTo(Trip::CLOSED);
        $trip->transitionTo(Trip::TRAVELLING);
        $trip->transitionTo(Trip::COMPLETED);

        $this->assertSame(['open', 'closed', 'travelling', 'completed'], $trip->transitions->pluck('to_status')->all());
        $this->assertSame('Ops', $trip->transitions->first()->user->name);

        $this->expectException(IllegalTripTransition::class);
        $trip->transitionTo(Trip::OPEN);
    }

    public function test_cancelling_needs_no_particular_state_before_travel_and_keeps_the_reason(): void
    {
        $trip = Trip::factory()->open()->create();
        $trip->transitionTo(Trip::CANCELLED, 'Flights not confirmed');

        $this->assertSame(Trip::CANCELLED, $trip->fresh()->status);
        $this->assertSame('Flights not confirmed', $trip->transitions()->latest('id')->first()->reason);
        $this->assertFalse($trip->fresh()->isLive());
    }

    public function test_the_database_refuses_more_seats_than_the_trip_has(): void
    {
        $trip = Trip::factory()->create(['capacity_total' => 10]);

        $trip->forceFill(['capacity_held' => 4, 'capacity_confirmed' => 6])->save();
        $this->assertSame(0, $trip->fresh()->seatsAvailable());

        $this->expectException(QueryException::class);
        $trip->forceFill(['capacity_confirmed' => 7])->save();
    }

    public function test_a_copy_shifts_every_date_and_leaves_history_behind(): void
    {
        $from = Trip::factory()->create(['departs_on' => '2026-07-10', 'returns_on' => '2026-07-24', 'capacity_total' => 45]);
        $from->priceTiers()->create(['occupancy' => 'quad', 'pax_type' => 'adult', 'amount_minor' => 2850000, 'currency' => 'MVR']);
        $from->flights()->create(['direction' => 'outbound', 'airline' => 'Saudia', 'flight_number' => 'SV 3301', 'from_airport' => 'MLE', 'to_airport' => 'JED', 'departs_at' => '2026-07-10 08:00', 'arrives_at' => '2026-07-10 11:30', 'pnr' => 'ABC123']);
        $makkah = $from->hotels()->create(['city' => 'makkah', 'name' => 'Swissotel', 'check_in_on' => '2026-07-10', 'check_out_on' => '2026-07-17']);
        $from->stops()->create(['sort_order' => 1, 'city' => 'makkah', 'arrives_on' => '2026-07-10', 'departs_on' => '2026-07-17', 'trip_hotel_id' => $makkah->id]);
        $from->excursions()->create(['title' => 'Taif', 'scheduled_on' => '2026-07-13', 'price_minor' => 15000, 'currency' => 'SAR']);
        $item = $from->checklist()->create(['title' => 'Send passports to agent', 'due_on' => '2026-06-10', 'is_blocking' => true]);
        $item->markDone(User::factory()->create());
        $from->costs()->create(['category' => 'airfare', 'currency' => 'USD', 'amount_minor' => 65000, 'is_per_person' => true, 'status' => 'paid']);

        $to = app(TripCopier::class)->copy($from, 'July 2027 Umrah', Carbon::parse('2027-07-05'));

        $this->assertSame('UMR-2027-07', $to->code);
        $this->assertSame('2027-07-19', $to->returns_on->toDateString());
        $this->assertSame(45, $to->capacity_total);
        $this->assertSame(0, $to->seatsTaken());
        $this->assertSame(2850000, $to->priceTiers->first()->amount_minor);
        $this->assertSame('2027-07-05 08:00', $to->flights->first()->departs_at->format('Y-m-d H:i'));
        $this->assertNull($to->flights->first()->pnr);
        $this->assertSame('2027-07-12', $to->hotels->first()->check_out_on->toDateString());
        $this->assertTrue($to->stops->first()->hotel->is($to->hotels->first()));
        $this->assertSame('2027-07-08', $to->excursions->first()->scheduled_on->toDateString());
        $this->assertSame('2027-06-05', $to->checklist->first()->due_on->toDateString());
        $this->assertFalse($to->checklist->first()->isDone());
        $this->assertSame('estimated', $to->costs->first()->status);
    }

    public function test_the_route_is_built_from_flights_and_hotels_once(): void
    {
        $trip = Trip::factory()->create(['departs_on' => '2027-07-05', 'returns_on' => '2027-07-19']);
        $trip->flights()->create(['direction' => 'outbound', 'airline' => 'Saudia', 'flight_number' => 'SV 3301', 'from_airport' => 'MLE', 'to_airport' => 'JED', 'departs_at' => '2027-07-05 08:00']);
        $trip->flights()->create(['direction' => 'return', 'airline' => 'Saudia', 'flight_number' => 'SV 3302', 'from_airport' => 'JED', 'to_airport' => 'MLE', 'departs_at' => '2027-07-19 20:00']);
        $trip->hotels()->create(['city' => TripHotel::MAKKAH, 'name' => 'A', 'check_in_on' => '2027-07-05', 'check_out_on' => '2027-07-12']);
        $trip->hotels()->create(['city' => TripHotel::MADINAH, 'name' => 'B', 'check_in_on' => '2027-07-12', 'check_out_on' => '2027-07-19']);

        $this->assertSame(4, app(RouteBuilder::class)->build($trip));
        $this->assertSame('MLE → Jeddah → Makkah → Madinah → Jeddah → MLE', $trip->fresh()->routeSummary());
        $this->assertSame(0, app(RouteBuilder::class)->build($trip->fresh()));
    }
}
