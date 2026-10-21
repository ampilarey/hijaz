<?php

namespace Tests\Feature\Payments;

use App\Models\Account;
use App\Models\Payment;
use App\Models\PriceTier;
use App\Services\Bookings\BookingDesk;
use App\Services\Payments\Ledger;
use App\Services\Payments\SlipMatcher;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PricedTrip;
use Tests\TestCase;

class SlipMatcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_slip_is_matched_by_the_booking_reference_or_the_id_number_in_it(): void
    {
        $trip = PricedTrip::make();
        [$customer, $head] = PricedTrip::family();
        $booking = app(BookingDesk::class)->open($trip, $customer, [['pilgrim_id' => $head->id, 'occupancy' => PriceTier::QUAD]]);

        $byReference = app(SlipMatcher::class)->propose(['remarks' => 'umrah '.strtolower(str_replace('-', '', $booking->reference))]);
        $this->assertTrue($byReference['payable']->is($booking));
        $this->assertSame('reference in the slip', $byReference['reason']);

        $byId = app(SlipMatcher::class)->propose(['remarks' => 'for '.$head->id_number]);
        $this->assertTrue($byId['payable']->is($booking));
        $this->assertSame('ID number in the slip', $byId['reason']);

        $this->assertNull(app(SlipMatcher::class)->propose(['remarks' => 'nothing useful'])['payable']);
    }

    public function test_it_warns_about_overpayment_strange_accounts_and_reused_references(): void
    {
        $trip = PricedTrip::make();
        [$customer, $head] = PricedTrip::family();
        $booking = app(BookingDesk::class)->open($trip, $customer, [['pilgrim_id' => $head->id, 'occupancy' => PriceTier::QUAD]]);
        Account::query()->create(['name' => 'BML MVR', 'kind' => Account::BANK, 'currency' => 'MVR', 'bank' => 'BML', 'account_number' => '7730000012345678']);
        $ledger = app(Ledger::class);
        $ledger->verify($ledger->record($booking, Money::ofMajor('1000', 'MVR'), Payment::BANK_TRANSFER, ['payer_reference' => 'TXN999']));

        $out = app(SlipMatcher::class)->propose([
            'remarks' => $booking->reference,
            'amount' => '50000',
            'currency' => 'MVR',
            'reference' => 'TXN999',
            'payee_account' => '1111222233334444',
        ]);

        $this->assertStringContainsString('already verified', implode(' ', $out['warnings']));
        $this->assertStringContainsString('not one of ours', implode(' ', $out['warnings']));
        $this->assertStringContainsString('only MVR 27,500 is due', implode(' ', $out['warnings']));

        $ours = app(SlipMatcher::class)->propose(['payee_account' => '7730-0000-1234-5678']);
        $this->assertSame('BML MVR', $ours['account']->name);
        $this->assertSame([], $ours['warnings']);
    }
}
