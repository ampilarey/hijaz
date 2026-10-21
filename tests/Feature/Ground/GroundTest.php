<?php

namespace Tests\Feature\Ground;

use App\Models\Account;
use App\Models\Booking;
use App\Models\BusGroup;
use App\Models\Incident;
use App\Models\Payment;
use App\Models\PriceTier;
use App\Models\RollCallMark;
use App\Models\TelegramLink;
use App\Models\Trip;
use App\Models\User;
use App\Services\Bookings\BookingDesk;
use App\Services\Bookings\Excursions;
use App\Services\Telegram\Linker;
use App\Support\Access;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\PricedTrip;
use Tests\TestCase;

class GroundTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        config(['portal.second_factor_required' => false]);
    }

    private function staff(string $role): User
    {
        return User::factory()->create()->assignRole($role);
    }

    /** @return array{Trip, Booking, User, BusGroup} */
    private function tripOnTheRoad(): array
    {
        $trip = PricedTrip::make();
        $trip->forceFill(['departs_on' => now()->addDays(2), 'returns_on' => now()->addDays(16), 'status' => 'closed'])->save();
        [$customer, $head, $spouse] = PricedTrip::family();
        $desk = app(BookingDesk::class);
        $booking = $desk->open($trip, $customer, [['pilgrim_id' => $head->id, 'occupancy' => PriceTier::QUAD], ['pilgrim_id' => $spouse->id, 'occupancy' => PriceTier::QUAD]]);
        $desk->confirm($booking);
        $leader = $this->staff(Access::GROUP_LEADER);
        $bus = $trip->busGroups()->create(['name' => 'Bus 1', 'leader_user_id' => $leader->id]);
        $booking->pilgrims()->update(['bus_group_id' => $bus->id]);

        return [$trip->fresh(), $booking->fresh(), $leader, $bus];
    }

    public function test_a_leader_sees_their_trip_and_bus_and_a_stranger_does_not(): void
    {
        [$trip, $booking, $leader, $bus] = $this->tripOnTheRoad();
        $other = $this->staff(Access::GROUP_LEADER);

        $this->actingAs($leader)->get(route('ground.index'))->assertOk()->assertSee($trip->name);
        $this->actingAs($leader)->get(route('ground.trip', $trip))->assertOk()->assertSee($booking->pilgrims->first()->pilgrim->name_en)->assertSee('Start a count');
        $this->actingAs($leader)->get(route('ground.snapshot', $trip))->assertOk()->assertJsonCount(2, 'travellers')->assertJsonMissing(['phone']);

        $this->actingAs($other)->get(route('ground.index'))->assertOk()->assertDontSee($trip->name);
        $this->actingAs($other)->get(route('ground.trip', $trip))->assertForbidden();
        $this->actingAs($this->staff(Access::FINANCE))->get(route('ground.index'))->assertForbidden();
        $this->actingAs($this->staff(Access::OPERATIONS))->get(route('ground.trip', $trip))->assertOk();
    }

    public function test_a_count_is_taken_from_the_phone_with_the_leaders_clock_deciding(): void
    {
        [$trip, $booking, $leader, $bus] = $this->tripOnTheRoad();
        [$a, $b] = $booking->pilgrims->all();

        $this->actingAs($leader)->post(route('ground.counts.start', $trip), ['moment' => 'Boarding at the hotel', 'bus_group_id' => $bus->id])->assertRedirect();
        $rollCall = $trip->rollCalls()->firstOrFail();
        $this->actingAs($leader)->get(route('ground.count', [$trip, $rollCall]))->assertOk()->assertSee($a->pilgrim->name_en);

        $response = $this->actingAs($leader)->postJson(route('ground.sync'), ['items' => [
            ['id' => 1, 'type' => 'mark', 'roll_call_id' => $rollCall->id, 'booking_pilgrim_id' => $a->id, 'state' => 'present', 'marked_at' => now()->subMinutes(2)->toIso8601String()],
            ['id' => 2, 'type' => 'mark', 'roll_call_id' => $rollCall->id, 'booking_pilgrim_id' => $b->id, 'state' => 'absent', 'marked_at' => now()->subMinute()->toIso8601String()],
            ['id' => 3, 'type' => 'mark', 'roll_call_id' => $rollCall->id, 'booking_pilgrim_id' => $a->id, 'state' => 'absent', 'marked_at' => now()->subMinutes(5)->toIso8601String()],
            ['id' => 4, 'type' => 'mark', 'roll_call_id' => $rollCall->id, 'booking_pilgrim_id' => 999999, 'state' => 'present'],
            ['id' => 5, 'type' => 'nonsense'],
        ]])->assertOk();

        $this->assertSame(['applied', 'applied', 'superseded', 'refused', 'refused'], collect($response->json('results'))->pluck('result')->all());
        $this->assertSame('present', RollCallMark::query()->where('booking_pilgrim_id', $a->id)->value('state'), 'a stale retry does not undo a later mark');
        $this->assertSame(1, $rollCall->fresh()->load('marks')->unaccountedFor()->count());

        $this->actingAs($this->staff(Access::READ_ONLY))->postJson(route('ground.sync'), ['items' => [['id' => 1, 'type' => 'mark', 'roll_call_id' => $rollCall->id, 'booking_pilgrim_id' => $a->id, 'state' => 'present']]])->assertForbidden();
    }

    public function test_an_incident_from_the_phone_is_raised_once_and_the_office_hears(): void
    {
        config(['telegram.bot_token' => '123456:TEST']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
        [$trip, $booking, $leader] = $this->tripOnTheRoad();
        $ops = $this->staff(Access::OPERATIONS);
        app(Linker::class)->redeem(app(Linker::class)->issueCode($ops), 300);
        $uuid = (string) Str::uuid();
        $item = ['id' => 'x', 'type' => 'incident', 'trip_id' => $trip->id, 'client_uuid' => $uuid, 'severity' => 'serious', 'category' => 'medical', 'summary' => 'Fainted at the Haram', 'location' => 'Gate 79', 'booking_pilgrim_id' => $booking->pilgrims->first()->id, 'happened_at' => now()->toIso8601String()];

        $first = $this->actingAs($leader)->postJson(route('ground.sync'), ['items' => [$item]])->assertOk()->json('results.0');
        $again = $this->actingAs($leader)->postJson(route('ground.sync'), ['items' => [$item]])->assertOk()->json('results.0');

        $this->assertSame('applied', $first['result']);
        $this->assertSame('duplicate', $again['result']);
        $this->assertSame(1, Incident::count());
        $incident = Incident::firstOrFail();
        $this->assertMatchesRegularExpression('/^INC-\d{4}-\d{4}$/', $incident->reference);
        $this->assertTrue($incident->bookingPilgrim->is($booking->pilgrims->first()));
        Http::assertSent(fn ($r): bool => str_ends_with($r->url(), '/sendMessage') && $r['chat_id'] === 300 && str_contains($r['text'], 'Serious incident') && str_contains($r['text'], 'Fainted at the Haram'));

        $this->actingAs($leader)->post(route('ground.incidents.raise', $trip), ['severity' => 'minor', 'category' => 'lost_luggage', 'summary' => 'Bag left on the bus'])->assertRedirect(route('ground.trip', $trip));
        $this->assertSame(2, Incident::count());
        $this->assertSame(1, TelegramLink::count());
    }

    public function test_riyal_collected_on_the_phone_is_a_verified_cash_payment_into_the_leaders_float(): void
    {
        [$trip, $booking, $leader] = $this->tripOnTheRoad();
        $taif = $trip->excursions()->create(['title' => 'Taif', 'is_included' => false, 'price_minor' => 15000, 'currency' => 'SAR']);
        $xb = app(Excursions::class)->book($taif, $booking, $booking->pilgrims->pluck('id')->all());
        $uuid = (string) Str::uuid();
        $item = ['id' => 'c', 'type' => 'cash', 'excursion_booking_id' => $xb->id, 'amount_minor' => 30000, 'client_uuid' => $uuid, 'paid_at' => now()->toIso8601String()];

        $this->actingAs($leader)->get(route('ground.trip', $trip))->assertOk()->assertSee('Paid SAR 300');
        $first = $this->actingAs($leader)->postJson(route('ground.sync'), ['items' => [$item]])->assertOk()->json('results.0');
        $again = $this->actingAs($leader)->postJson(route('ground.sync'), ['items' => [$item]])->assertOk()->json('results.0');

        $this->assertSame('applied', $first['result']);
        $this->assertSame('duplicate', $again['result']);
        $payment = Payment::firstOrFail();
        $this->assertSame(Payment::SUCCEEDED, $payment->status);
        $this->assertSame(30000, $payment->amount_minor);
        $this->assertSame(Account::LEADER_CASH, $payment->account->kind);
        $this->assertTrue($payment->account->holder->is($leader));
        $this->assertSame('paid', $xb->fresh()->status);
        $this->assertTrue($payment->reviewer->is($leader));
    }

    public function test_the_service_worker_is_served(): void
    {
        $this->get('/ground-sw.js')->assertOk();
    }
}
