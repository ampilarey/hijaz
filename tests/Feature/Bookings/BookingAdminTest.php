<?php

namespace Tests\Feature\Bookings;

use App\Filament\Resources\Bookings\BookingResource;
use App\Filament\Resources\Bookings\Pages\CreateBooking;
use App\Filament\Resources\Bookings\Pages\ViewBooking;
use App\Filament\Resources\Bookings\RelationManagers\ExcursionBookingsRelationManager;
use App\Filament\Resources\Bookings\RelationManagers\LinesRelationManager;
use App\Filament\Resources\Bookings\RelationManagers\PilgrimsRelationManager;
use App\Filament\Resources\Bookings\RelationManagers\ScheduleRelationManager;
use App\Filament\Resources\ExcursionBookings\ExcursionBookingResource;
use App\Models\Booking;
use App\Models\Discount;
use App\Models\ExcursionBooking;
use App\Models\PriceTier;
use App\Models\User;
use App\Services\Bookings\BookingDesk;
use App\Support\Access;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\PricedTrip;
use Tests\TestCase;

class BookingAdminTest extends TestCase
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

    public function test_who_may_open_what(): void
    {
        $trip = PricedTrip::make();
        [$customer, $head] = PricedTrip::family();
        $booking = app(BookingDesk::class)->open($trip, $customer, [['pilgrim_id' => $head->id, 'occupancy' => PriceTier::QUAD]]);

        foreach (Access::ROLES as $role) {
            $this->actingAs($this->staff($role))->get(BookingResource::getUrl())->assertOk();
            $this->actingAs($this->staff($role))->get(BookingResource::getUrl('view', ['record' => $booking]))->assertOk();
            $this->actingAs($this->staff($role))->get(ExcursionBookingResource::getUrl())->assertOk();
        }

        $this->actingAs($this->staff(Access::REGISTRATION))->get(BookingResource::getUrl('create'))->assertOk();
        $this->actingAs($this->staff(Access::OPERATIONS))->get(BookingResource::getUrl('create'))->assertOk();
        $this->actingAs($this->staff(Access::FINANCE))->get(BookingResource::getUrl('create'))->assertForbidden();
        $this->actingAs($this->staff(Access::GROUP_LEADER))->get(BookingResource::getUrl('create'))->assertForbidden();
        $this->actingAs($this->staff(Access::READ_ONLY))->get(BookingResource::getUrl('edit', ['record' => $booking]))->assertForbidden();
    }

    public function test_registration_opens_a_booking_for_a_family_from_the_form(): void
    {
        $this->actingAs($this->staff(Access::REGISTRATION));
        $trip = PricedTrip::make();
        [$customer, $head, $spouse, $child] = PricedTrip::family();

        Livewire::test(CreateBooking::class)
            ->fillForm([
                'trip_id' => $trip->id,
                'customer_id' => $customer->id,
                'party' => [
                    ['pilgrim_id' => $head->id, 'occupancy' => PriceTier::DOUBLE],
                    ['pilgrim_id' => $spouse->id, 'occupancy' => PriceTier::DOUBLE],
                    ['pilgrim_id' => $child->id, 'occupancy' => PriceTier::QUAD],
                ],
                'notes' => 'Lower floor please',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $booking = Booking::firstOrFail();
        $this->assertSame(3, $booking->seats);
        $this->assertSame(['MVR' => 3200000 * 2 + 2000000], $booking->totals);
        $this->assertSame('Lower floor please', $booking->notes);
        $this->assertSame(3, $trip->fresh()->capacity_held);
    }

    public function test_a_full_trip_refuses_the_booking_on_the_form(): void
    {
        $this->actingAs($this->staff(Access::REGISTRATION));
        $trip = PricedTrip::make(capacity: 1);
        [$customer, $head, $spouse] = PricedTrip::family();

        Livewire::test(CreateBooking::class)
            ->fillForm(['trip_id' => $trip->id, 'customer_id' => $customer->id, 'party' => [
                ['pilgrim_id' => $head->id, 'occupancy' => PriceTier::QUAD],
                ['pilgrim_id' => $spouse->id, 'occupancy' => PriceTier::QUAD],
            ]])
            ->call('create')
            ->assertNotified();

        $this->assertSame(0, Booking::count());
        $this->assertSame(0, $trip->fresh()->capacity_held);
    }

    public function test_the_page_confirms_discounts_and_cancels_and_finance_only_discounts(): void
    {
        $trip = PricedTrip::make();
        [$customer, $head] = PricedTrip::family();
        $booking = app(BookingDesk::class)->open($trip, $customer, [['pilgrim_id' => $head->id, 'occupancy' => PriceTier::QUAD]]);

        $this->actingAs($this->staff(Access::OPERATIONS));
        Livewire::test(ViewBooking::class, ['record' => $booking->getKey()])
            ->assertActionHidden('discount')
            ->callAction('confirm');
        $this->assertSame(Booking::CONFIRMED, $booking->fresh()->status);
        $this->assertSame(1, $trip->fresh()->capacity_confirmed);

        $this->actingAs($this->staff(Access::FINANCE));
        Livewire::test(ViewBooking::class, ['record' => $booking->getKey()])
            ->assertActionHidden('confirm')
            ->assertActionHidden('cancel')
            ->callAction('discount', data: ['kind' => Discount::PERCENT, 'currency' => 'MVR', 'value' => '10', 'reason' => 'Returning pilgrim'])
            ->assertHasNoFormErrors();
        $this->assertSame(2565000, $booking->fresh()->totals['MVR']);
        $this->assertSame('Returning pilgrim', Discount::firstOrFail()->reason);

        $this->actingAs($this->staff(Access::OPERATIONS));
        Livewire::test(ViewBooking::class, ['record' => $booking->getKey()])
            ->callAction('cancel', data: ['reason' => 'Visa refused']);
        $this->assertSame(Booking::CANCELLED, $booking->fresh()->status);
        $this->assertSame(0, $trip->fresh()->capacity_confirmed);
    }

    public function test_travellers_charges_instalments_and_additional_trips_are_managed_from_the_page(): void
    {
        $this->actingAs($this->staff(Access::REGISTRATION));
        $trip = PricedTrip::make();
        $taif = $trip->excursions()->create(['title' => 'Taif Ziyarah', 'is_included' => false, 'price_minor' => 15000, 'currency' => 'SAR']);
        [$customer, $head, $spouse] = PricedTrip::family();
        $booking = app(BookingDesk::class)->open($trip, $customer, [['pilgrim_id' => $head->id, 'occupancy' => PriceTier::QUAD]]);

        Livewire::test(PilgrimsRelationManager::class, ['ownerRecord' => $booking, 'pageClass' => ViewBooking::class])
            ->callTableAction('add', data: ['pilgrim_id' => $spouse->id, 'occupancy' => PriceTier::QUAD])
            ->assertHasNoTableActionErrors();
        $this->assertSame(2, $booking->fresh()->seats);

        Livewire::test(LinesRelationManager::class, ['ownerRecord' => $booking->fresh(), 'pageClass' => ViewBooking::class])
            ->callTableAction('add_line', data: ['type' => 'fee', 'description' => 'Visa fee', 'currency' => 'USD', 'unit_amount_minor' => '120', 'quantity' => 2])
            ->assertHasNoTableActionErrors();
        $this->assertSame(24000, $booking->fresh()->totals['USD']);

        Livewire::test(ScheduleRelationManager::class, ['ownerRecord' => $booking->fresh(), 'pageClass' => ViewBooking::class])
            ->callTableAction('create', data: ['label' => 'Deposit', 'currency' => 'MVR', 'amount_minor' => '10000', 'due_on' => now()->addWeek()->toDateString()])
            ->assertHasNoTableActionErrors();
        $this->assertSame(1000000, $booking->schedule()->first()->amount_minor);

        $bp = $booking->pilgrims()->first();
        Livewire::test(ExcursionBookingsRelationManager::class, ['ownerRecord' => $booking->fresh(), 'pageClass' => ViewBooking::class])
            ->callTableAction('book', data: ['trip_excursion_id' => $taif->id, 'booking_pilgrim_ids' => [$bp->id]])
            ->assertHasNoTableActionErrors();
        $xb = ExcursionBooking::firstOrFail();
        $this->assertSame(15000, $xb->total_minor);

        Livewire::test(PilgrimsRelationManager::class, ['ownerRecord' => $booking->fresh(), 'pageClass' => ViewBooking::class])
            ->callTableAction('remove', $booking->pilgrims()->where('pilgrim_id', $spouse->id)->first());
        $this->assertSame(1, $booking->fresh()->seats);
    }

    public function test_lapsed_holds_are_released_by_the_scheduled_command(): void
    {
        $trip = PricedTrip::make();
        [$customer, $head] = PricedTrip::family();
        $booking = app(BookingDesk::class)->open($trip, $customer, [['pilgrim_id' => $head->id, 'occupancy' => PriceTier::QUAD]]);

        $this->artisan('bookings:expire-holds')->expectsOutputToContain('Released 0')->assertSuccessful();

        $this->travel(8)->days();
        $this->artisan('bookings:expire-holds')->expectsOutputToContain('Released 1')->assertSuccessful();
        $this->assertSame(Booking::CANCELLED, $booking->fresh()->status);
        $this->assertSame(0, $trip->fresh()->capacity_held);
    }
}
