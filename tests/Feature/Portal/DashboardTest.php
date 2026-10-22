<?php

namespace Tests\Feature\Portal;

use App\Models\Account;
use App\Models\LedgerCategory;
use App\Models\LedgerEntry;
use App\Models\PriceTier;
use App\Models\User;
use App\Services\Bookings\BookingDesk;
use App\Services\Finance\Books;
use App\Services\Finance\MoneyByMonth;
use App\Support\Access;
use App\Support\Money;
use Database\Seeders\AccountsSeeder;
use Database\Seeders\LedgerCategoriesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\PricedTrip;
use Tests\TestCase;

/** The home page after sign-in: figures first, each shown only to a role that may see it. */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, AccountsSeeder::class, LedgerCategoriesSeeder::class]);
        config(['portal.second_factor_required' => false]);
    }

    private function confirmedBooking(): void
    {
        $trip = PricedTrip::make();
        [$customer, $head] = PricedTrip::family();
        $desk = app(BookingDesk::class);
        $desk->confirm($desk->open($trip, $customer, [['pilgrim_id' => $head->id, 'occupancy' => PriceTier::QUAD]]));
    }

    public function test_finance_sees_the_money_figures_and_the_charts(): void
    {
        $this->confirmedBooking();
        $finance = User::factory()->create()->assignRole(Access::FINANCE);

        $this->actingAs($finance)->get('/portal')->assertOk()
            ->assertSee('Next departure')
            ->assertSee('1 of 40')
            ->assertSee('Still owed to us')
            ->assertSee('MVR 28,500')
            ->assertSee('Received this month')
            ->assertSee('Payments to verify')
            ->assertSee('Money in and out')
            ->assertSee('Seats by trip');
    }

    public function test_registration_sees_the_trips_but_not_the_books(): void
    {
        $this->confirmedBooking();
        $registration = User::factory()->create()->assignRole(Access::REGISTRATION);

        $this->actingAs($registration)->get('/portal')->assertOk()
            ->assertSee('Seats filled')
            ->assertSee('Seats by trip')
            ->assertDontSee('Received this month')
            ->assertDontSee('Money in and out')
            ->assertDontSee('Payments to verify');
    }

    public function test_months_add_up_income_less_refunds_and_leave_transfers_out(): void
    {
        $books = app(Books::class);
        $mvr = Account::query()->where('currency', 'MVR')->orderBy('sort_order')->firstOrFail();
        $other = Account::query()->where('currency', 'MVR')->whereKeyNot($mvr->getKey())->first();
        $income = LedgerCategory::query()->where('kind', LedgerCategory::INCOME)->firstOrFail();
        $expense = LedgerCategory::query()->where('kind', LedgerCategory::EXPENSE)->firstOrFail();

        $books->record($mvr, LedgerEntry::INCOME, LedgerEntry::IN, Money::ofMajor('10000', 'MVR'), $income, Carbon::today(), 'Package');
        $books->record($mvr, LedgerEntry::INCOME, LedgerEntry::OUT, Money::ofMajor('1000', 'MVR'), $income, Carbon::today(), 'Refund');
        $books->record($mvr, LedgerEntry::EXPENSE, LedgerEntry::OUT, Money::ofMajor('4000', 'MVR'), $expense, Carbon::today(), 'Hotel');
        $books->record($mvr, LedgerEntry::INCOME, LedgerEntry::IN, Money::ofMajor('500', 'MVR'), $income, Carbon::today()->subMonthNoOverflow(), 'Last month');
        if ($other) {
            $books->transfer($mvr, $other, Money::ofMajor('2000', 'MVR'), Carbon::today(), 'Move cash');
        }

        $months = array_values(MoneyByMonth::forLastMonths(6));

        $this->assertCount(6, $months);
        $this->assertSame(900000, $months[5]['in']);
        $this->assertSame(400000, $months[5]['out']);
        $this->assertSame(50000, $months[4]['in']);
    }
}
