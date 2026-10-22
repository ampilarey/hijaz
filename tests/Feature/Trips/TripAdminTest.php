<?php

namespace Tests\Feature\Trips;

use App\Filament\Resources\Trips\Pages\CreateTrip;
use App\Filament\Resources\Trips\Pages\EditTrip;
use App\Filament\Resources\Trips\RelationManagers\ChecklistRelationManager;
use App\Filament\Resources\Trips\RelationManagers\CostsRelationManager;
use App\Filament\Resources\Trips\RelationManagers\ExcursionsRelationManager;
use App\Filament\Resources\Trips\RelationManagers\FlightsRelationManager;
use App\Filament\Resources\Trips\RelationManagers\PriceTiersRelationManager;
use App\Filament\Resources\Trips\TripResource;
use App\Models\PriceTier;
use App\Models\Trip;
use App\Models\User;
use App\Services\Bookings\BookingDesk;
use App\Services\Telegram\Bot;
use App\Services\Telegram\Linker;
use App\Support\Access;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\PricedTrip;
use Tests\TestCase;

class TripAdminTest extends TestCase
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

    public function test_everyone_sees_trips_and_only_operations_and_admin_create_them(): void
    {
        $trip = Trip::factory()->create();

        foreach (Access::ROLES as $role) {
            $this->actingAs($this->staff($role))->get(TripResource::getUrl())->assertOk();
            $this->actingAs($this->staff($role))->get(TripResource::getUrl('view', ['record' => $trip]))->assertOk();
        }

        $this->actingAs($this->staff(Access::OPERATIONS))->get(TripResource::getUrl('edit', ['record' => $trip]))->assertOk();
        $this->actingAs($this->staff(Access::FINANCE))->get(TripResource::getUrl('edit', ['record' => $trip]))->assertForbidden();

        $this->actingAs($this->staff(Access::OPERATIONS))->get(TripResource::getUrl('create'))->assertOk();
        $this->actingAs($this->staff(Access::FINANCE))->get(TripResource::getUrl('create'))->assertForbidden();
        $this->actingAs($this->staff(Access::REGISTRATION))->get(TripResource::getUrl('create'))->assertForbidden();
    }

    public function test_operations_creates_a_trip_and_lands_on_its_page(): void
    {
        $this->actingAs($this->staff(Access::OPERATIONS));

        Livewire::test(CreateTrip::class)
            ->fillForm(['name' => 'July 2027 Umrah', 'departs_on' => '2027-07-05', 'returns_on' => '2027-07-19', 'capacity_total' => 40])
            ->call('create')
            ->assertHasNoFormErrors();

        $trip = Trip::firstOrFail();
        $this->assertSame('UMR-2027-07', $trip->code);
        $this->assertSame(Trip::DRAFT, $trip->status);
    }

    public function test_a_return_before_departure_is_refused(): void
    {
        $this->actingAs($this->staff(Access::OPERATIONS));

        Livewire::test(CreateTrip::class)
            ->fillForm(['name' => 'Backwards', 'departs_on' => '2027-07-19', 'returns_on' => '2027-07-05', 'capacity_total' => 40])
            ->call('create')
            ->assertHasFormErrors(['returns_on']);
    }

    public function test_seats_cannot_drop_below_what_is_taken(): void
    {
        $this->actingAs($this->staff(Access::OPERATIONS));
        $trip = Trip::factory()->create(['capacity_total' => 40]);
        $trip->forceFill(['capacity_confirmed' => 12])->save();

        Livewire::test(EditTrip::class, ['record' => $trip->getKey()])
            ->fillForm(['capacity_total' => 10])
            ->call('save')
            ->assertHasFormErrors(['capacity_total']);
    }

    public function test_prices_are_entered_in_whole_units_and_stored_in_minor_units(): void
    {
        $this->actingAs($this->staff(Access::OPERATIONS));
        $trip = Trip::factory()->create();

        Livewire::test(PriceTiersRelationManager::class, ['ownerRecord' => $trip, 'pageClass' => EditTrip::class])
            ->callTableAction('create', data: ['occupancy' => 'quad', 'pax_type' => 'adult', 'currency' => 'MVR', 'amount_minor' => '28500'])
            ->assertHasNoFormErrors();
        Livewire::test(PriceTiersRelationManager::class, ['ownerRecord' => $trip, 'pageClass' => EditTrip::class])
            ->callTableAction('create', data: ['occupancy' => 'single', 'pax_type' => 'adult', 'currency' => 'USD', 'amount_minor' => '299.50'])
            ->assertHasNoFormErrors();

        $this->assertSame(2850000, $trip->priceTiers()->where('occupancy', 'quad')->value('amount_minor'));
        $this->assertSame(29950, $trip->priceTiers()->where('occupancy', 'single')->value('amount_minor'));
        $this->assertSame('USD 299.50', $trip->priceTiers()->where('occupancy', 'single')->first()->money()->format());
    }

    public function test_flights_hotels_and_excursions_are_added_from_the_trip_page(): void
    {
        $this->actingAs($this->staff(Access::OPERATIONS));
        $trip = Trip::factory()->create();

        Livewire::test(FlightsRelationManager::class, ['ownerRecord' => $trip, 'pageClass' => EditTrip::class])
            ->callTableAction('create', data: ['direction' => 'outbound', 'airline' => 'Saudia', 'flight_number' => 'SV 3301', 'from_airport' => 'mle', 'to_airport' => 'jed', 'departs_at' => '2027-07-05 08:00'])
            ->assertHasNoFormErrors();
        $this->assertSame('MLE', $trip->flights()->first()->from_airport);

        Livewire::test(ExcursionsRelationManager::class, ['ownerRecord' => $trip, 'pageClass' => EditTrip::class])
            ->callTableAction('create', data: ['title' => 'Makkah Ziyarah', 'is_included' => true, 'currency' => 'SAR'])
            ->assertHasNoFormErrors();
        Livewire::test(ExcursionsRelationManager::class, ['ownerRecord' => $trip, 'pageClass' => EditTrip::class])
            ->callTableAction('create', data: ['title' => 'Taif Ziyarah', 'is_included' => false, 'currency' => 'SAR', 'price_minor' => '150'])
            ->assertHasNoFormErrors();

        $this->assertSame('Included', $trip->excursions()->where('title', 'Makkah Ziyarah')->first()->priceLabel());
        $this->assertSame('SAR 150 per person', $trip->excursions()->where('title', 'Taif Ziyarah')->first()->priceLabel());
    }

    public function test_a_checklist_item_is_ticked_by_operations_and_a_leader_but_not_read_only(): void
    {
        $trip = Trip::factory()->create();
        $item = $trip->checklist()->create(['title' => 'Confirm bus', 'due_on' => today()->subDay()]);

        $this->assertTrue($this->staff(Access::GROUP_LEADER)->can('tick', $item));
        $this->assertFalse($this->staff(Access::READ_ONLY)->can('tick', $item));

        $this->actingAs($this->staff(Access::OPERATIONS));
        Livewire::test(ChecklistRelationManager::class, ['ownerRecord' => $trip, 'pageClass' => EditTrip::class])
            ->callTableAction('done', $item);

        $this->assertTrue($item->fresh()->isDone());
    }

    public function test_costs_are_for_finance_only(): void
    {
        $trip = Trip::factory()->create();

        $this->actingAs($this->staff(Access::OPERATIONS));
        $this->assertFalse(CostsRelationManager::canViewForRecord($trip, EditTrip::class));
        $this->actingAs($this->staff(Access::FINANCE));
        $this->assertTrue(CostsRelationManager::canViewForRecord($trip, EditTrip::class));

        Livewire::test(CostsRelationManager::class, ['ownerRecord' => $trip, 'pageClass' => EditTrip::class])
            ->callTableAction('create', data: ['category' => 'makkah_hotel', 'supplier' => 'Swissotel', 'currency' => 'SAR', 'amount_minor' => '42000', 'is_per_person' => false, 'status' => 'committed'])
            ->assertHasNoFormErrors();

        $this->assertSame('SAR 42,000', $trip->costs()->first()->unit()->format());
    }

    public function test_the_trip_page_offers_the_allowed_transitions_and_copy(): void
    {
        $this->actingAs($this->staff(Access::OPERATIONS));
        $trip = Trip::factory()->create();

        Livewire::test(EditTrip::class, ['record' => $trip->getKey()])
            ->assertActionVisible('to_open')
            ->assertActionDoesNotExist('to_travelling')
            ->callAction('to_open');

        $this->assertSame(Trip::OPEN, $trip->fresh()->status);

        Livewire::test(EditTrip::class, ['record' => $trip->getKey()])
            ->callAction('copy', data: ['name' => 'Next year', 'departs_on' => $trip->departs_on->copy()->addYear()->toDateString()]);

        $this->assertSame(2, Trip::count());
        $this->assertSame(Trip::DRAFT, Trip::where('name', 'Next year')->firstOrFail()->status);
    }

    public function test_operations_delete_a_trip_with_no_bookings_and_everything_set_on_it_goes_too(): void
    {
        $trip = Trip::factory()->create();
        $trip->flights()->create(['direction' => 'outbound', 'airline' => 'Saudia', 'flight_number' => 'SV 789', 'from_airport' => 'MLE', 'to_airport' => 'JED', 'departs_at' => $trip->departs_on]);
        $trip->checklist()->create(['title' => 'Book hotels']);

        $this->actingAs($this->staff(Access::OPERATIONS));
        Livewire::test(EditTrip::class, ['record' => $trip->getRouteKey()])
            ->assertActionVisible('delete')
            ->callAction('delete');

        $this->assertModelMissing($trip);
        $this->assertDatabaseCount('trip_flights', 0);
        $this->assertDatabaseCount('trip_checklist_items', 0);
        $this->assertDatabaseHas('audit_logs', ['auditable_type' => $trip->getMorphClass(), 'auditable_id' => (string) $trip->id, 'event' => 'deleted']);
    }

    public function test_a_trip_with_a_booking_is_cancelled_not_deleted_even_by_admin(): void
    {
        $trip = PricedTrip::make();
        [$customer, $head] = PricedTrip::family();
        app(BookingDesk::class)->open($trip, $customer, [['pilgrim_id' => $head->id, 'occupancy' => PriceTier::QUAD]]);

        $this->actingAs($this->staff(Access::ADMIN));
        Livewire::test(EditTrip::class, ['record' => $trip->getRouteKey()])->assertActionHidden('delete');

        $this->expectException(\DomainException::class);
        $trip->delete();
    }

    public function test_registration_cannot_delete_a_trip(): void
    {
        $trip = Trip::factory()->create();

        $registration = $this->staff(Access::REGISTRATION);

        $this->assertFalse($registration->can('delete', $trip));
        $this->assertTrue($this->staff(Access::OPERATIONS)->can('delete', $trip));
    }

    public function test_a_trip_can_be_saved_before_its_dates_are_fixed_and_opens_only_once_they_are(): void
    {
        $this->actingAs($this->staff(Access::OPERATIONS));

        Livewire::test(CreateTrip::class)
            ->fillForm(['name' => 'Hajj 2027', 'capacity_total' => 40])
            ->call('create')
            ->assertHasNoFormErrors();

        $trip = Trip::query()->where('name', 'Hajj 2027')->firstOrFail();
        $this->assertNull($trip->departs_on);
        $this->assertSame(Trip::UNDATED_CODE, $trip->code);
        $this->assertSame('Hajj 2027 (dates not set)', $trip->label);

        Livewire::test(EditTrip::class, ['record' => $trip->getRouteKey()])->callAction('to_open');
        $this->assertSame(Trip::DRAFT, $trip->fresh()->status);

        $trip->update(['departs_on' => '2027-05-20', 'returns_on' => '2027-06-10']);
        $this->assertSame('UMR-2027-05', $trip->fresh()->code);

        Livewire::test(EditTrip::class, ['record' => $trip->getRouteKey()])->callAction('to_open');
        $this->assertSame(Trip::OPEN, $trip->fresh()->status);

        $this->expectException(\DomainException::class);
        $trip->fresh()->update(['departs_on' => null]);
    }

    public function test_nobody_is_booked_on_a_trip_without_dates(): void
    {
        $trip = Trip::factory()->create(['departs_on' => null, 'returns_on' => null]);
        [$customer, $head] = PricedTrip::family();

        $this->expectException(\InvalidArgumentException::class);
        app(BookingDesk::class)->open($trip, $customer, [['pilgrim_id' => $head->id, 'occupancy' => PriceTier::QUAD]]);
    }

    public function test_a_cancellation_needs_a_reason(): void
    {
        $this->actingAs($this->staff(Access::OPERATIONS));
        $trip = Trip::factory()->open()->create();

        Livewire::test(EditTrip::class, ['record' => $trip->getKey()])
            ->callAction('to_cancelled', data: ['reason' => ''])
            ->assertHasActionErrors(['reason']);

        Livewire::test(EditTrip::class, ['record' => $trip->getKey()])
            ->callAction('to_cancelled', data: ['reason' => 'Visa quota cut']);

        $this->assertSame(Trip::CANCELLED, $trip->fresh()->status);
    }

    public function test_the_bot_lists_trips_and_describes_one(): void
    {
        config(['telegram.bot_token' => '123:T', 'telegram.bot_username' => 'bot']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        $user = $this->staff(Access::OPERATIONS);
        app(Linker::class)->redeem(app(Linker::class)->issueCode($user), 77);
        $trip = Trip::factory()->open()->create(['name' => 'July 2027 Umrah', 'departs_on' => now()->addMonth(), 'returns_on' => now()->addMonth()->addDays(14)]);

        $texts = [];
        $deliver = fn (int $id, string $text) => $this->postJson('/api/telegram/webhook', ['update_id' => $id, 'message' => ['message_id' => $id, 'chat' => ['id' => 77], 'from' => ['id' => 77], 'text' => $text]], ['X-Telegram-Bot-Api-Secret-Token' => Bot::webhookSecret()])->assertOk();
        $deliver(1, '/trip');
        $deliver(2, '/trip '.$trip->code);
        $deliver(3, '/trip nothing-like-this');

        Http::assertSent(function ($request) use (&$texts): bool {
            if (str_ends_with($request->url(), '/sendMessage')) {
                $texts[] = $request['text'];
            }

            return true;
        });

        $this->assertStringContainsString($trip->code, $texts[0]);
        $this->assertStringContainsString('Seats: 0 of 40', $texts[1]);
        $this->assertStringContainsString('No trip matches', $texts[2]);
    }
}
