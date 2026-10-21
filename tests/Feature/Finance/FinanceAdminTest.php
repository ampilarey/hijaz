<?php

namespace Tests\Feature\Finance;

use App\Filament\Pages\ReportsPage;
use App\Filament\Resources\LedgerCategories\LedgerCategoryResource;
use App\Filament\Resources\LedgerEntries\LedgerEntryResource;
use App\Filament\Resources\LedgerEntries\Pages\ListLedgerEntries;
use App\Filament\Resources\Trips\Pages\EditTrip;
use App\Filament\Resources\Trips\RelationManagers\TravellersRelationManager;
use App\Models\Account;
use App\Models\ExchangeRate;
use App\Models\LedgerCategory;
use App\Models\LedgerEntry;
use App\Models\PriceTier;
use App\Models\User;
use App\Services\Bookings\BookingDesk;
use App\Services\Finance\Books;
use App\Support\Access;
use Database\Seeders\AccountsSeeder;
use Database\Seeders\LedgerCategoriesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\Images;
use Tests\Support\PricedTrip;
use Tests\TestCase;

class FinanceAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(AccountsSeeder::class);
        $this->seed(LedgerCategoriesSeeder::class);
        config(['portal.second_factor_required' => false]);
        ExchangeRate::query()->create(['from_currency' => 'USD', 'to_currency' => 'MVR', 'rate' => '15.42', 'as_of' => '2020-01-01']);
    }

    private function staff(string $role): User
    {
        return User::factory()->create()->assignRole($role);
    }

    public function test_who_may_open_the_books_and_the_reports(): void
    {
        foreach ([Access::ADMIN, Access::FINANCE, Access::READ_ONLY] as $role) {
            $this->actingAs($this->staff($role))->get(LedgerEntryResource::getUrl())->assertOk();
            $this->actingAs($this->staff($role))->get(ReportsPage::getUrl())->assertOk();
            $this->actingAs($this->staff($role))->get(route('reports.show', ['report' => 'outstanding']))->assertOk()->assertHeader('content-type', 'application/pdf');
        }

        $this->actingAs($this->staff(Access::OPERATIONS))->get(LedgerEntryResource::getUrl())->assertForbidden();
        $this->actingAs($this->staff(Access::OPERATIONS))->get(ReportsPage::getUrl())->assertOk();
        $this->actingAs($this->staff(Access::GROUP_LEADER))->get(ReportsPage::getUrl())->assertForbidden();
        $this->actingAs($this->staff(Access::GROUP_LEADER))->get(route('reports.show', ['report' => 'outstanding']))->assertForbidden();
        $this->actingAs($this->staff(Access::FINANCE))->get(LedgerCategoryResource::getUrl())->assertOk();
        $this->actingAs($this->staff(Access::FINANCE))->get(route('reports.show', ['report' => 'trip_pnl']))->assertStatus(422);
        $this->actingAs($this->staff(Access::FINANCE))->get(route('reports.show', ['report' => 'nothing']))->assertNotFound();
        $this->actingAs($this->staff(Access::FINANCE))->get(route('reports.show', ['report' => 'yearly', 'year' => 2026, 'format' => 'csv']))->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    public function test_finance_writes_the_books_from_the_page_and_read_only_cannot(): void
    {
        $trip = PricedTrip::make();
        $bankMvr = Account::defaultFor(Account::BANK, 'MVR');
        $bankUsd = Account::defaultFor(Account::BANK, 'USD');
        $cash = Account::defaultFor(Account::CASH, 'MVR');

        $this->actingAs($this->staff(Access::FINANCE));
        Livewire::test(ListLedgerEntries::class)
            ->callTableAction('entry', data: ['kind' => 'expense', 'account_id' => $bankUsd->id, 'category_id' => LedgerCategory::bySlug('makkah_hotel')->id, 'amount_minor' => '12000', 'occurred_on' => today()->toDateString(), 'trip_id' => $trip->id, 'description' => 'Hotel deposit', 'counterparty' => 'Swissotel', 'reference' => 'INV-1', 'evidence' => UploadedFile::fake()->createWithContent('advice.jpg', Images::photo())])
            ->assertHasNoTableActionErrors()
            ->callTableAction('transfer', data: ['from_account_id' => $cash->id, 'to_account_id' => $bankMvr->id, 'amount_minor' => '5000', 'occurred_on' => today()->toDateString(), 'description' => 'Cash to bank'])
            ->assertHasNoTableActionErrors()
            ->callTableAction('purchase', data: ['paid_account_id' => $bankMvr->id, 'paid_minor' => '15700', 'received_account_id' => $bankUsd->id, 'received_minor' => '1000', 'charges_minor' => '50', 'occurred_on' => today()->toDateString(), 'counterparty' => 'BML'])
            ->assertHasNoTableActionErrors()
            ->callTableAction('tt', data: ['account_id' => $bankUsd->id, 'amount_minor' => '8000', 'charges_minor' => '25', 'category_id' => LedgerCategory::bySlug('airfare')->id, 'trip_id' => $trip->id, 'occurred_on' => today()->toDateString(), 'counterparty' => 'Saudia', 'beneficiary_bank' => 'Al Rajhi', 'reference' => 'TT-1'])
            ->assertHasNoTableActionErrors();

        $this->assertSame(8, LedgerEntry::count());
        $hotel = LedgerEntry::query()->where('description', 'Hotel deposit')->firstOrFail();
        $this->assertSame(1200000, $hotel->amount_minor);
        $this->assertSame(18504000, $hotel->mvr_minor);
        $this->assertNotNull($hotel->evidence());
        $books = app(Books::class);
        $this->assertSame(-1575000 + 500000, $books->balance($bankMvr)->minor);
        $this->assertSame(100000 - 1200000 - 800000 - 2500, $books->balance($bankUsd)->minor);

        Livewire::test(ListLedgerEntries::class)
            ->callTableAction('void', $hotel, data: ['reason' => 'Duplicate'])
            ->assertHasNoTableActionErrors();
        $this->assertTrue($hotel->fresh()->isVoided());

        $this->actingAs($this->staff(Access::READ_ONLY));
        Livewire::test(ListLedgerEntries::class)
            ->assertTableActionHidden('entry')
            ->assertTableActionHidden('void', LedgerEntry::query()->where('description', 'Cash to bank')->first());
    }

    public function test_the_reports_page_renders_and_the_usd_list_is_built_from_the_travellers_tab(): void
    {
        $trip = PricedTrip::make();
        [$customer, $head, $spouse] = PricedTrip::family();
        $desk = app(BookingDesk::class);
        $booking = $desk->open($trip, $customer, [['pilgrim_id' => $head->id, 'occupancy' => PriceTier::QUAD], ['pilgrim_id' => $spouse->id, 'occupancy' => PriceTier::QUAD]]);
        $desk->confirm($booking);

        $this->actingAs($this->staff(Access::OPERATIONS));
        Livewire::test(TravellersRelationManager::class, ['ownerRecord' => $trip->fresh(), 'pageClass' => EditTrip::class])
            ->callTableBulkAction('usd_list', $booking->pilgrims, data: ['amount_minor' => '500', 'currency' => 'USD', 'note' => 'first trip'])
            ->assertHasNoTableBulkActionErrors();
        $this->assertSame(2, $trip->usdAllocations()->count());
        $this->assertSame(50000, $trip->usdAllocations()->first()->amount_minor);

        $this->actingAs($this->staff(Access::FINANCE));
        Livewire::test(ReportsPage::class)
            ->fillForm(['report' => 'usd_list', 'trip' => $trip->id])
            ->call('run')
            ->assertSee('USD allocation list')
            ->assertSee($head->name_en)
            ->assertSee('USD 1,000');

        $this->get(route('reports.show', ['report' => 'usd_list', 'trip' => $trip->id]))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->get(ReportsPage::getUrl(['report' => 'trip_pnl', 'trip' => $trip->id]))->assertOk()->assertSee('Profit and loss');

        Livewire::test(TravellersRelationManager::class, ['ownerRecord' => $trip->fresh(), 'pageClass' => EditTrip::class])
            ->callTableBulkAction('usd_list_off', $booking->pilgrims->take(1));
        $this->assertSame(1, $trip->usdAllocations()->count());
    }
}
