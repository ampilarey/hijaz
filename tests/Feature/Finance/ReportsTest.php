<?php

namespace Tests\Feature\Finance;

use App\Models\Account;
use App\Models\ExchangeRate;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\PriceTier;
use App\Models\Trip;
use App\Services\Bookings\BookingDesk;
use App\Services\Bookings\Excursions;
use App\Services\Finance\Books;
use App\Services\Finance\Reports;
use App\Services\Payments\Ledger;
use App\Support\Money;
use Database\Seeders\AccountsSeeder;
use Database\Seeders\LedgerCategoriesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PricedTrip;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccountsSeeder::class);
        $this->seed(LedgerCategoriesSeeder::class);
        ExchangeRate::query()->create(['from_currency' => 'USD', 'to_currency' => 'MVR', 'rate' => '15.42', 'as_of' => '2020-01-01']);
        ExchangeRate::query()->create(['from_currency' => 'SAR', 'to_currency' => 'MVR', 'rate' => '4.11', 'as_of' => '2020-01-01']);
    }

    private function tripWithMoney(): Trip
    {
        $trip = PricedTrip::make();
        [$customer, $head, $spouse] = PricedTrip::family();
        $desk = app(BookingDesk::class);
        $booking = $desk->open($trip, $customer, [['pilgrim_id' => $head->id, 'occupancy' => PriceTier::QUAD], ['pilgrim_id' => $spouse->id, 'occupancy' => PriceTier::SINGLE]]);
        $desk->confirm($booking);
        $desk->discount($booking->fresh(), 'fixed', '500', 'MVR', 'Returning');
        $ledger = app(Ledger::class);
        $ledger->verify($ledger->record($booking->fresh(), Money::ofMajor('20000', 'MVR'), Payment::BANK_TRANSFER, ['paid_on' => '2026-10-05']));
        $ledger->verify($ledger->record($booking->fresh(), Money::ofMajor('299.50', 'USD'), Payment::CASH, ['paid_on' => '2026-10-06']));
        $taif = $trip->excursions()->create(['title' => 'Taif', 'is_included' => false, 'price_minor' => 15000, 'currency' => 'SAR', 'cost_minor' => 20000, 'cost_currency' => 'SAR']);
        $xb = app(Excursions::class)->book($taif, $booking->fresh(), [$booking->pilgrims->first()->id]);
        $ledger->verify($ledger->record($xb, Money::ofMajor('150', 'SAR'), Payment::CASH, ['paid_on' => '2026-10-07']));
        $books = app(Books::class);
        $books->record(Account::defaultFor(Account::BANK, 'USD'), LedgerEntry::EXPENSE, LedgerEntry::OUT, Money::ofMajor('1000', 'USD'), $books->category('makkah_hotel'), now()->setDate(2026, 10, 8), 'Hotel deposit', ['trip_id' => $trip->id]);
        $books->record(Account::defaultFor(Account::BANK, 'MVR'), LedgerEntry::EXPENSE, LedgerEntry::OUT, Money::ofMajor('3000', 'MVR'), $books->category('office'), now()->setDate(2026, 10, 9), 'Rent');
        $trip->costs()->create(['category' => 'airfare', 'currency' => 'USD', 'amount_minor' => 50000, 'is_per_person' => true, 'status' => 'estimated']);

        return $trip->fresh();
    }

    public function test_the_trip_profit_and_loss_adds_up_in_mvr_at_the_dated_rates(): void
    {
        $trip = $this->tripWithMoney();
        $report = app(Reports::class)->tripProfit($trip);

        $figures = collect($report->figures)->pluck('value', 'label');
        $this->assertSame('2', $figures['Confirmed travellers']);
        // 20,000 MVR + 299.50 USD × 15.42 + 150 SAR × 4.11
        $this->assertSame('MVR 25,234.79', $figures['Income (MVR at dated rates)']);
        $this->assertSame('MVR 15,420', $figures['Expenses (MVR at dated rates)']);
        $this->assertStringStartsWith('MVR 9,814.79', $figures['Margin']);
        $this->assertStringContainsString('per traveller', $figures['Margin']);
        $this->assertSame('MVR 8,000', $figures['Still owed by pilgrims']);
        $this->assertSame('USD 1,000', $figures['Costs estimated on the trip']);
        $this->assertSame(['Additional trip sales', 'Package sales', 'Room upgrades'], collect($report->sections[0]->rows)->pluck('category')->sort()->values()->all());
        $this->assertStringStartsWith('%PDF', app(Reports::class)->pdf($report)->output());
        $this->assertStringContainsString('Profit and loss', $report->csv());
    }

    public function test_the_yearly_report_and_the_cash_book(): void
    {
        $trip = $this->tripWithMoney();
        $reports = app(Reports::class);

        $year = $reports->yearly(2026);
        $figures = collect($year->figures)->pluck('value', 'label');
        $this->assertSame('MVR 25,234.79', $figures['Income (MVR)']);
        $this->assertSame('MVR 18,420', $figures['Expenses (MVR)']);
        $this->assertSame('MVR 6,814.79', $figures['Net (MVR)']);
        $oct = collect($year->sections[0]->rows)->firstWhere('month', 'Oct');
        $this->assertStringContainsString('MVR 20,000', $oct['income']);
        $byTrip = collect($year->sections[3]->rows)->pluck('net', 'trip');
        $this->assertSame('MVR 9,814.79', $byTrip[$trip->code]);
        $this->assertSame('-MVR 3,000', $byTrip['Overheads']);

        $book = $reports->cashBook(Account::defaultFor(Account::BANK, 'MVR'), null, null);
        $this->assertCount(2, $book->sections[0]->rows);
        $this->assertSame('MVR 17,000', $book->sections[0]->rows[1]['balance']);
        $this->assertSame('MVR 17,000', collect($book->figures)->pluck('value', 'label')['Balance today']);
    }

    public function test_purchases_outstanding_collections_discounts_and_the_rest_render(): void
    {
        $trip = $this->tripWithMoney();
        $books = app(Books::class);
        $books->buyCurrency(Account::defaultFor(Account::BANK, 'MVR'), Money::ofMajor('15700', 'MVR'), Account::defaultFor(Account::BANK, 'USD'), Money::ofMajor('1000', 'USD'), now(), null, ['counterparty' => 'BML']);
        $reports = app(Reports::class);

        $purchases = $reports->currencyPurchases(null, null);
        $this->assertSame('MVR 280', collect($purchases->figures)->pluck('value', 'label')['Exchange cost against the table rate']);

        $outstanding = $reports->outstanding();
        $this->assertCount(1, $outstanding->sections[0]->rows);
        $this->assertSame('MVR 8,000', $outstanding->sections[0]->rows[0]['due']);

        $collections = $reports->collections($trip, null, null);
        $this->assertSame('3', collect($collections->figures)->pluck('value', 'label')['Payments (net of refunds)']);

        $discounts = $reports->discounts($trip, null, null);
        $this->assertSame('MVR 500', collect($discounts->figures)->pluck('value', 'label')['Given in MVR']);

        $this->assertCount(2, $reports->documentsStatus($trip)->sections[0]->rows);
        $this->assertSame('1', $reports->excursions($trip)->sections[0]->rows[0]['going']);
        $this->assertSame('2', collect($reports->demographics($trip)->figures)->pluck('value', 'label')['Pilgrims']);
        $this->assertSame('3', collect($reports->verifications($trip, null, null)->figures)->pluck('value', 'label')['Payments reviewed']);
        $this->assertStringStartsWith('%PDF', $reports->pdf($reports->tripSummary($trip))->output());
    }

    public function test_the_usd_list_dedupes_by_id_number_and_prints_with_a_cover_letter(): void
    {
        $trip = $this->tripWithMoney();
        $bps = $trip->travellers()->get();
        $trip->usdAllocations()->create(['booking_pilgrim_id' => $bps[0]->id, 'amount_minor' => 50000]);
        $trip->usdAllocations()->create(['booking_pilgrim_id' => $bps[1]->id, 'amount_minor' => 50000, 'note' => 'spouse']);
        $bps[1]->pilgrim->forceFill(['id_number' => $bps[0]->pilgrim->id_number])->save();

        $list = app(Reports::class)->usdList($trip->fresh());
        $this->assertCount(1, $list->sections[0]->rows);
        $this->assertSame('500.00', $list->sections[0]->rows[0]['amount']);
        $pdf = app(Reports::class)->pdf($list, letter: true)->output();
        $this->assertStringStartsWith('%PDF', $pdf);
    }
}
