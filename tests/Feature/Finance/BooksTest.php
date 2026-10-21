<?php

namespace Tests\Feature\Finance;

use App\Models\Account;
use App\Models\Document;
use App\Models\ExchangeRate;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\PriceTier;
use App\Services\Bookings\BookingDesk;
use App\Services\Finance\Books;
use App\Services\Payments\Ledger;
use App\Support\Money;
use Database\Seeders\AccountsSeeder;
use Database\Seeders\LedgerCategoriesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Images;
use Tests\Support\PricedTrip;
use Tests\TestCase;

class BooksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        $this->seed(AccountsSeeder::class);
        $this->seed(LedgerCategoriesSeeder::class);
    }

    private function books(): Books
    {
        return app(Books::class);
    }

    public function test_a_verified_payment_posts_itself_once_and_a_refund_posts_out(): void
    {
        $trip = PricedTrip::make();
        [$customer, $head] = PricedTrip::family();
        $booking = app(BookingDesk::class)->open($trip, $customer, [['pilgrim_id' => $head->id, 'occupancy' => PriceTier::QUAD]]);
        $ledger = app(Ledger::class);
        $payment = $ledger->verify($ledger->record($booking, Money::ofMajor('10000', 'MVR'), Payment::BANK_TRANSFER, ['payer_reference' => 'BML1']));

        $entry = LedgerEntry::query()->where('payment_id', $payment->id)->firstOrFail();
        $this->assertSame(LedgerEntry::IN, $entry->direction);
        $this->assertSame(1000000, $entry->amount_minor);
        $this->assertSame(1000000, $entry->mvr_minor);
        $this->assertSame('package_sales', $entry->category->slug);
        $this->assertSame($trip->id, $entry->trip_id);
        $this->assertSame('Bank MVR', $entry->account->name);
        $this->assertSame('BML1', $entry->reference);

        $this->assertNull($this->books()->postPayment($payment->fresh()), 'posting again is a no-op');
        $this->assertSame(0, $this->books()->postMissingPayments());

        $ledger->refund($payment, Money::ofMajor('2000', 'MVR'), 'Dropped out');
        $out = LedgerEntry::query()->where('direction', LedgerEntry::OUT)->firstOrFail();
        $this->assertSame(200000, $out->amount_minor);
        $this->assertSame('refunds', $out->category->slug);
        $this->assertSame(800000, $this->books()->balance(Account::defaultFor(Account::BANK, 'MVR'))->minor);
    }

    public function test_a_dollar_payment_has_no_mvr_figure_until_a_rate_is_set_and_cash_goes_to_the_cash_box(): void
    {
        $trip = PricedTrip::make();
        [$customer, $head] = PricedTrip::family();
        $booking = app(BookingDesk::class)->open($trip, $customer, [['pilgrim_id' => $head->id, 'occupancy' => PriceTier::SINGLE]]);
        $ledger = app(Ledger::class);
        $payment = $ledger->verify($ledger->record($booking, Money::ofMajor('299.50', 'USD'), Payment::CASH));

        $entry = LedgerEntry::query()->where('payment_id', $payment->id)->firstOrFail();
        $this->assertNull($entry->mvr_minor);
        $this->assertSame('room_upgrades', $entry->category->slug);
        $this->assertSame('Office cash USD', $entry->account->name);
    }

    public function test_an_expense_with_its_evidence_and_a_void_that_keeps_the_row(): void
    {
        ExchangeRate::query()->create(['from_currency' => 'SAR', 'to_currency' => 'MVR', 'rate' => '4.11', 'as_of' => '2026-01-01']);
        $sar = Account::query()->create(['name' => 'Leader SAR', 'kind' => Account::LEADER_CASH, 'currency' => 'SAR']);
        $trip = PricedTrip::make();

        $entry = $this->books()->record($sar, LedgerEntry::EXPENSE, LedgerEntry::OUT, Money::ofMajor('1200', 'SAR'), $this->books()->category('transport'), now(), 'Coach Makkah–Taif', [
            'trip_id' => $trip->id, 'counterparty' => 'Al Safwa buses', 'reference' => 'INV-77',
            'evidence' => Images::photo(), 'evidence_name' => 'receipt.jpg', 'evidence_mime' => 'image/jpeg',
        ]);

        $this->assertSame(493200, $entry->mvr_minor);
        $this->assertSame('4.11000000', $entry->rate_snapshot);
        $this->assertSame(Document::EVIDENCE, $entry->evidence()?->type);
        $this->assertSame('INV-77', $entry->evidence()->number);
        $this->assertSame(-120000, $this->books()->balance($sar)->minor);

        $this->books()->void($entry, 'Entered twice');
        $this->assertTrue($entry->fresh()->isVoided());
        $this->assertSame('Entered twice', $entry->fresh()->void_reason);
        $this->assertSame(0, $this->books()->balance($sar)->minor);
        $this->assertSame(1, LedgerEntry::count());
    }

    public function test_a_transfer_is_two_entries_in_one_currency_and_voids_together(): void
    {
        $bank = Account::defaultFor(Account::BANK, 'MVR');
        $cash = Account::defaultFor(Account::CASH, 'MVR');

        [$out, $in] = $this->books()->transfer($cash, $bank, Money::ofMajor('5000', 'MVR'), now(), 'Cash to the bank');
        $this->assertSame($out->group_key, $in->group_key);
        $this->assertSame(-500000, $this->books()->balance($cash)->minor);
        $this->assertSame(500000, $this->books()->balance($bank)->minor);

        $this->books()->void($in, 'Wrong amount');
        $this->assertTrue($out->fresh()->isVoided());

        $this->expectException(\InvalidArgumentException::class);
        $this->books()->transfer($cash, Account::defaultFor(Account::BANK, 'USD'), Money::ofMajor('1', 'MVR'), now(), 'Nope');
    }

    public function test_buying_dollars_keeps_the_realised_rate_and_the_charges(): void
    {
        ExchangeRate::query()->create(['from_currency' => 'USD', 'to_currency' => 'MVR', 'rate' => '15.42', 'as_of' => '2026-01-01']);
        $mvr = Account::defaultFor(Account::BANK, 'MVR');
        $usd = Account::defaultFor(Account::BANK, 'USD');

        $entries = $this->books()->buyCurrency($mvr, Money::ofMajor('15700', 'MVR'), $usd, Money::ofMajor('1000', 'USD'), now(), Money::ofMajor('50', 'MVR'), ['counterparty' => 'BML', 'reference' => 'FX-1']);

        $this->assertCount(3, $entries);
        [$out, $in, $charges] = $entries;
        $this->assertSame('15.70000000', $out->rate_snapshot);
        $this->assertSame(1570000, $in->mvr_minor, 'the dollars are worth what was paid for them');
        $this->assertSame('bank_charges', $charges->category->slug);
        $this->assertSame(-1575000, $this->books()->balance($mvr)->minor);
        $this->assertSame(100000, $this->books()->balance($usd)->minor);
        $this->assertSame('15.42000000', $in->meta['table_rate']);

        $this->expectException(\InvalidArgumentException::class);
        $this->books()->buyCurrency($mvr, Money::ofMajor('100', 'MVR'), $mvr, Money::ofMajor('100', 'MVR'), now());
    }

    public function test_a_tt_is_an_expense_against_its_trip_with_the_beneficiary_and_charges(): void
    {
        $usd = Account::defaultFor(Account::BANK, 'USD');
        $trip = PricedTrip::make();

        $entries = $this->books()->sendTt($usd, Money::ofMajor('12000', 'USD'), $this->books()->category('makkah_hotel'), now(), 'Swissotel Al Maqam', ['trip_id' => $trip->id, 'reference' => 'TT-9', 'beneficiary_bank' => 'Al Rajhi'], Money::ofMajor('25', 'USD'));

        $this->assertCount(2, $entries);
        $this->assertSame('Swissotel Al Maqam', $entries[0]->counterparty);
        $this->assertSame('Al Rajhi', $entries[0]->meta['beneficiary_bank']);
        $this->assertTrue($entries[0]->meta['tt']);
        $this->assertSame($trip->id, $entries[1]->trip_id);
        $this->assertSame(-1202500, $this->books()->balance($usd)->minor);
    }
}
