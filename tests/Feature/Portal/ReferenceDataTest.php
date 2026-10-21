<?php

namespace Tests\Feature\Portal;

use App\Models\Account;
use App\Models\ExchangeRate;
use App\Models\Island;
use App\Models\User;
use App\Support\Access;
use App\Support\Money;
use App\Support\Settings;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

class ReferenceDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_islands_are_seeded_by_atoll_and_edits_survive_a_reseed(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertGreaterThan(180, Island::count());
        $this->assertSame('Kaafu', Island::where('name', 'Maafushi')->firstOrFail()->atoll_name);
        $this->assertSame('K. Maafushi', Island::where('name', 'Maafushi')->firstOrFail()->label);
        $this->assertTrue(Island::where('atoll_code', 'Gn')->where('name', 'Fuvahmulah')->exists());
        $this->assertTrue(Island::where('atoll_code', 'S')->where('name', 'Hithadhoo')->exists());

        Island::where('name', 'Maafushi')->update(['name_dv' => 'މާފުށި']);
        $before = Island::count();

        $this->seed(DatabaseSeeder::class);

        $this->assertSame($before, Island::count());
        $this->assertSame('މާފުށި', Island::where('name', 'Maafushi')->firstOrFail()->name_dv);
    }

    public function test_the_default_accounts_are_seeded_once(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->assertSame(4, Account::count());

        Account::first()->update(['name' => 'Renamed']);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(4, Account::count());
        $this->assertSame('Renamed', Account::first()->name);
    }

    public function test_an_account_number_is_encrypted_at_rest_and_masked_in_lists(): void
    {
        $account = Account::factory()->create(['account_number' => '7730000123456']);

        $raw = DB::table('accounts')->where('id', $account->id)->value('account_number');
        $this->assertStringStartsWith('enc:', $raw);
        $this->assertStringNotContainsString('7730000123456', $raw);

        $this->assertSame('7730000123456', $account->fresh()->account_number);
        $this->assertSame('•••••••••3456', $account->fresh()->maskedNumber());
    }

    public function test_money_converts_between_whole_and_minor_units_exactly(): void
    {
        $this->assertSame(2850000, Money::ofMajor('28500', 'MVR')->minor);
        $this->assertSame(29950, Money::ofMajor('299.50', 'USD')->minor);
        $this->assertSame(29905, Money::ofMajor('299.05', 'USD')->minor);
        $this->assertSame('MVR 28,500', Money::ofMinor(2850000, 'MVR')->format());
        $this->assertSame('USD 299.50', Money::ofMinor(29950, 'USD')->format());
        $this->assertSame('-SAR 150', Money::ofMinor(-15000, 'SAR')->format());
        $this->assertSame('299.05', Money::ofMinor(29905, 'USD')->major());
    }

    public function test_money_refuses_three_decimals_and_unknown_currencies(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::ofMajor('1.005', 'MVR');
    }

    public function test_money_never_adds_across_currencies(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::ofMinor(100, 'MVR')->add(Money::ofMinor(100, 'USD'));
    }

    public function test_the_rate_in_force_is_the_latest_dated_on_or_before_the_day(): void
    {
        ExchangeRate::factory()->create(['rate' => '15.40', 'as_of' => '2026-01-01']);
        ExchangeRate::factory()->create(['rate' => '15.42', 'as_of' => '2026-03-01']);
        ExchangeRate::factory()->create(['rate' => '15.50', 'as_of' => '2026-06-01']);

        $this->assertSame('15.42000000', ExchangeRate::inForce('USD', 'MVR', now()->parse('2026-04-15'))->rate);
        $this->assertSame('15.50000000', ExchangeRate::inForce('USD', 'MVR', now()->parse('2026-06-01'))->rate);
        $this->assertNull(ExchangeRate::inForce('USD', 'MVR', now()->parse('2025-12-31')));
        $this->assertNull(ExchangeRate::inForce('SAR', 'MVR'));
        $this->assertNull(ExchangeRate::inForce('MVR', 'MVR'));
    }

    public function test_a_rate_converts_to_the_nearest_minor_unit(): void
    {
        $rate = ExchangeRate::factory()->create(['rate' => '15.42', 'as_of' => '2026-01-01']);

        $converted = $rate->convert(Money::ofMajor('300', 'USD'));

        $this->assertSame('MVR 4,626', $converted->format());
        $this->assertSame('1 USD = 15.42 MVR', $rate->label);
    }

    public function test_settings_have_defaults_and_remember_changes(): void
    {
        $this->assertSame('Hijaz Hajj & Umrah', Settings::get(Settings::COMPANY_NAME));
        $this->assertSame(6, Settings::get(Settings::PASSPORT_VALIDITY_MONTHS));

        Settings::set(Settings::PASSPORT_VALIDITY_MONTHS, 7);

        $this->assertSame(7, Settings::get(Settings::PASSPORT_VALIDITY_MONTHS));
    }

    public function test_finance_can_open_rates_and_accounts_and_registration_cannot(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        config(['portal.second_factor_required' => false]);

        $this->actingAs(User::factory()->create()->assignRole(Access::FINANCE))->get('/portal/exchange-rates')->assertOk();
        $this->actingAs(User::factory()->create()->assignRole(Access::FINANCE))->get('/portal/accounts')->assertOk();
        $this->actingAs(User::factory()->create()->assignRole(Access::REGISTRATION))->get('/portal/exchange-rates')->assertForbidden();
        $this->actingAs(User::factory()->create()->assignRole(Access::REGISTRATION))->get('/portal/islands')->assertOk();
        $this->actingAs(User::factory()->create()->assignRole(Access::OPERATIONS))->get('/portal/settings')->assertOk();
        $this->actingAs(User::factory()->create()->assignRole(Access::GROUP_LEADER))->get('/portal/settings')->assertForbidden();
    }
}
