<?php

namespace Tests\Feature\Operations;

use App\Filament\Pages\Scan;
use App\Filament\Resources\Trips\Pages\EditTrip;
use App\Filament\Resources\Trips\Pages\ViewTrip;
use App\Filament\Resources\Trips\RelationManagers\BusesRelationManager;
use App\Filament\Resources\Trips\RelationManagers\RoomsRelationManager;
use App\Filament\Resources\Trips\RelationManagers\TravellersRelationManager;
use App\Filament\Resources\Trips\TripResource;
use App\Models\Booking;
use App\Models\BookingPilgrim;
use App\Models\Card;
use App\Models\PriceTier;
use App\Models\Room;
use App\Models\Trip;
use App\Models\TripHotel;
use App\Models\User;
use App\Services\Bookings\BookingDesk;
use App\Services\Operations\Cards;
use App\Services\Telegram\Bot;
use App\Services\Telegram\Linker;
use App\Support\Access;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\PricedTrip;
use Tests\TestCase;

class OperationsAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        $this->seed(RolesAndPermissionsSeeder::class);
        config(['portal.second_factor_required' => false]);
    }

    private function staff(string $role): User
    {
        return User::factory()->create()->assignRole($role);
    }

    /** @return array{Trip, Booking} */
    private function trip(): array
    {
        $trip = PricedTrip::make();
        $trip->hotels()->create(['city' => TripHotel::MAKKAH, 'name' => 'Swissotel', 'check_in_on' => $trip->departs_on, 'check_out_on' => $trip->returns_on]);
        [$customer, $head, $spouse] = PricedTrip::family();
        $desk = app(BookingDesk::class);
        $booking = $desk->open($trip, $customer, [
            ['pilgrim_id' => $head->id, 'occupancy' => PriceTier::DOUBLE],
            ['pilgrim_id' => $spouse->id, 'occupancy' => PriceTier::DOUBLE],
        ]);
        $desk->confirm($booking);

        return [$trip->fresh(), $booking->fresh()];
    }

    public function test_who_may_open_the_board_the_lists_and_the_scan_page(): void
    {
        [$trip] = $this->trip();

        foreach ([Access::ADMIN, Access::OPERATIONS, Access::FINANCE, Access::REGISTRATION, Access::GROUP_LEADER, Access::READ_ONLY] as $role) {
            $this->actingAs($this->staff($role))->get(TripResource::getUrl('readiness', ['record' => $trip]))->assertOk();
            $this->actingAs($this->staff($role))->get(TripResource::getUrl('lists', ['record' => $trip]))->assertOk();
            $this->actingAs($this->staff($role))->get(route('lists.manifest', ['trip' => $trip, 'preset' => 'airline']))->assertOk()->assertHeader('content-type', 'application/pdf');
        }

        $this->actingAs($this->staff(Access::GROUP_LEADER))->get(Scan::getUrl())->assertOk();
        $this->actingAs($this->staff(Access::OPERATIONS))->get(Scan::getUrl())->assertOk();
        $this->actingAs($this->staff(Access::FINANCE))->get(Scan::getUrl())->assertForbidden();
        $this->actingAs($this->staff(Access::FINANCE))->get(route('lists.cards', ['kind' => 'customer', 'trip' => $trip->id]))->assertForbidden();
        $this->actingAs($this->staff(Access::REGISTRATION))->get(route('lists.cards', ['kind' => 'customer', 'trip' => $trip->id]))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->actingAs($this->staff(Access::READ_ONLY))->get(route('lists.manifest', ['trip' => $trip, 'preset' => 'office', 'format' => 'csv']))->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->actingAs($this->staff(Access::READ_ONLY))->get(route('lists.manifest', ['trip' => $trip, 'preset' => 'nope']))->assertNotFound();
    }

    public function test_operations_builds_buses_and_rooms_from_the_trip_page(): void
    {
        $this->actingAs($this->staff(Access::OPERATIONS));
        [$trip, $booking] = $this->trip();
        $hotel = $trip->hotels->first();

        Livewire::test(BusesRelationManager::class, ['ownerRecord' => $trip, 'pageClass' => EditTrip::class])
            ->callTableAction('create', data: ['name' => 'Bus 1', 'capacity' => 45, 'leader_name' => 'Hassan', 'leader_phone' => '7771111'])
            ->assertHasNoTableActionErrors();
        $bus = $trip->busGroups()->firstOrFail();

        Livewire::test(BusesRelationManager::class, ['ownerRecord' => $trip, 'pageClass' => EditTrip::class])
            ->callTableAction('assign', $bus, data: ['ids' => $booking->pilgrims->pluck('id')->all()])
            ->assertHasNoTableActionErrors();
        $this->assertSame(2, $bus->members()->count());

        Livewire::test(RoomsRelationManager::class, ['ownerRecord' => $trip, 'pageClass' => EditTrip::class])
            ->callTableAction('create', data: ['trip_hotel_id' => $hotel->id, 'label' => '1204', 'capacity' => 2, 'gender' => Room::FAMILY])
            ->assertHasNoTableActionErrors();
        $room = $hotel->rooms()->firstOrFail();

        Livewire::test(RoomsRelationManager::class, ['ownerRecord' => $trip, 'pageClass' => EditTrip::class])
            ->callTableAction('assign', $room, data: ['ids' => $booking->pilgrims->pluck('id')->all()])
            ->assertHasNoTableActionErrors();
        $this->assertSame(2, $room->assignments()->count());

        Livewire::test(RoomsRelationManager::class, ['ownerRecord' => $trip, 'pageClass' => EditTrip::class])
            ->callTableAction('check')
            ->assertNotified('Rooming is settled');

        $this->actingAs($this->staff(Access::READ_ONLY));
        Livewire::test(BusesRelationManager::class, ['ownerRecord' => $trip, 'pageClass' => ViewTrip::class])
            ->assertTableActionHidden('assign', $bus);
    }

    public function test_bulk_actions_on_the_travellers_list(): void
    {
        $this->actingAs($this->staff(Access::OPERATIONS));
        [$trip, $booking] = $this->trip();
        $bus = $trip->busGroups()->create(['name' => 'Bus 2', 'capacity' => 10]);
        $taif = $trip->excursions()->create(['title' => 'Taif', 'is_included' => false, 'price_minor' => 15000, 'currency' => 'SAR']);
        $records = $booking->pilgrims;

        Livewire::test(TravellersRelationManager::class, ['ownerRecord' => $trip, 'pageClass' => EditTrip::class])
            ->assertCanSeeTableRecords($records)
            ->callTableBulkAction('bus', $records, data: ['bus_group_id' => $bus->id])
            ->assertHasNoTableBulkActionErrors()
            ->callTableBulkAction('visa', $records, data: ['visa_status' => 'submitted', 'visa_agent' => 'Al Noor', 'on' => today()->toDateString()])
            ->assertHasNoTableBulkActionErrors()
            ->callTableBulkAction('excursion', $records, data: ['trip_excursion_id' => $taif->id])
            ->assertHasNoTableBulkActionErrors();

        $this->assertSame(2, $bus->members()->count());
        $this->assertSame(['submitted', 'submitted'], BookingPilgrim::query()->pluck('visa_status')->all());
        $this->assertSame('Al Noor', BookingPilgrim::query()->first()->visa_agent);
        $this->assertSame(2, $booking->excursionBookings()->first()->participants()->count());

        Livewire::test(TravellersRelationManager::class, ['ownerRecord' => $trip, 'pageClass' => EditTrip::class])
            ->callTableAction('edit', $records->first(), data: ['ticket_number' => '0651234567890', 'seat_number' => '12A', 'visa_status' => 'issued'])
            ->assertHasNoTableActionErrors();
        $this->assertSame('0651234567890', $records->first()->fresh()->ticket_number);

        $this->actingAs($this->staff(Access::READ_ONLY));
        Livewire::test(TravellersRelationManager::class, ['ownerRecord' => $trip, 'pageClass' => ViewTrip::class])
            ->assertTableBulkActionHidden('bus')
            ->assertTableActionHidden('edit', $records->first());
    }

    public function test_the_readiness_board_blocks_travelling_until_an_admin_overrides(): void
    {
        [$trip, $booking] = $this->trip();
        $trip->transitionTo(Trip::CLOSED);

        $this->actingAs($this->staff(Access::OPERATIONS));
        Livewire::test(EditTrip::class, ['record' => $trip->getKey()])
            ->callAction('to_travelling', data: ['reason' => 'They will manage'])
            ->assertNotified('An Admin has to override the readiness board for this trip');
        $this->assertSame(Trip::CLOSED, $trip->fresh()->status);

        $this->actingAs($this->staff(Access::ADMIN));
        Livewire::test(EditTrip::class, ['record' => $trip->getKey()])
            ->callAction('to_travelling', data: ['reason' => 'Visas arrive at the airport'])
            ->assertHasNoFormErrors();
        $this->assertSame(Trip::TRAVELLING, $trip->fresh()->status);
        $last = $trip->transitions()->reorder('id', 'desc')->first();
        $this->assertStringContainsString('Override by', $last->reason);
        $this->assertStringContainsString('visas not issued', $last->reason);
    }

    public function test_the_scan_page_finds_a_card_and_checks_the_traveller_in(): void
    {
        [$trip, $booking] = $this->trip();
        $bus = $trip->busGroups()->create(['name' => 'Bus 1']);
        $bp = $booking->pilgrims->first();
        $bp->forceFill(['bus_group_id' => $bus->id])->save();
        $card = app(Cards::class)->issue($bp, Card::CUSTOMER);

        $this->actingAs($this->staff(Access::GROUP_LEADER));
        $this->get(Scan::getUrl().'?t='.$card->qr_token)->assertOk()->assertSee($bp->pilgrim->name_en)->assertSee('Bus 1');

        Livewire::test(Scan::class)
            ->set('code', 'hjz-c-'.substr($card->serial, -6))
            ->call('lookup')
            ->assertSet('cardId', $card->id)
            ->call('checkIn');
        $this->assertNotNull($bp->fresh()->checked_in_at);

        Livewire::test(Scan::class)->set('code', 'HJZ-C-999999')->call('lookup')->assertSet('cardId', null)->assertNotified();
    }

    public function test_telegram_answers_ready(): void
    {
        config(['telegram.bot_token' => '123456:TEST']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
        [$trip] = $this->trip();
        $ops = $this->staff(Access::OPERATIONS);
        app(Linker::class)->redeem(app(Linker::class)->issueCode($ops), 500);

        $this->postJson('/api/telegram/webhook', [
            'update_id' => 1,
            'message' => ['message_id' => 1, 'chat' => ['id' => 500, 'type' => 'private'], 'from' => ['id' => 500, 'first_name' => 'O'], 'text' => '/ready '.$trip->code],
        ], ['X-Telegram-Bot-Api-Secret-Token' => Bot::webhookSecret()])->assertOk();

        Http::assertSent(fn ($r): bool => str_ends_with($r->url(), '/sendMessage') && str_contains($r['text'], '⛔ 2 visas not issued') && str_contains($r['text'], '/readiness'));
    }
}
