<?php

namespace Tests\Feature\Payments;

use App\Models\Booking;
use App\Models\ExchangeRate;
use App\Models\ExcursionBooking;
use App\Models\Payment;
use App\Models\PriceTier;
use App\Services\Bookings\BookingDesk;
use App\Services\Bookings\Excursions;
use App\Services\Payments\Ledger;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PricedTrip;
use Tests\TestCase;

class LedgerTest extends TestCase
{
    use RefreshDatabase;

    private function booking(string $occupancy = PriceTier::QUAD): Booking
    {
        $trip = PricedTrip::make();
        [$customer, $head] = PricedTrip::family();

        return app(BookingDesk::class)->open($trip, $customer, [['pilgrim_id' => $head->id, 'occupancy' => $occupancy]]);
    }

    public function test_a_recorded_payment_waits_for_finance_and_counts_for_nothing_yet(): void
    {
        $booking = $this->booking();

        $payment = app(Ledger::class)->record($booking, Money::ofMajor('10000', 'MVR'), Payment::BANK_TRANSFER, ['payer_reference' => 'BML123']);

        $this->assertMatchesRegularExpression('/^HJZ-P-\d{4}-\d{4}$/', $payment->reference);
        $this->assertSame(Payment::AWAITING_REVIEW, $payment->status);
        $this->assertSame(0, $booking->fresh()->paidIn('MVR')->minor);
        $this->assertCount(2, $payment->transactions);
    }

    public function test_verifying_settles_the_balance_in_the_payments_own_currency(): void
    {
        $booking = $this->booking();
        $ledger = app(Ledger::class);
        $payment = $ledger->record($booking, Money::ofMajor('10000', 'MVR'), Payment::CASH);

        $ledger->verify($payment);

        $this->assertSame(Payment::SUCCEEDED, $payment->fresh()->status);
        $this->assertSame(1000000, $booking->fresh()->paidIn('MVR')->minor);
        $this->assertSame('MVR 18,500 due', $booking->fresh()->balanceSummary());
        $this->assertNull($payment->allocations->first()->rate_snapshot);
    }

    public function test_paying_dollars_against_a_rufiyaa_balance_needs_a_rate_and_keeps_it(): void
    {
        $booking = $this->booking();
        $ledger = app(Ledger::class);
        $payment = $ledger->record($booking, Money::ofMajor('1000', 'USD'), Payment::BANK_TRANSFER, ['paid_on' => '2026-10-20']);

        try {
            $ledger->verify($payment, [['currency' => 'MVR', 'amount_minor' => 1542000]]);
            $this->fail('A rate was invented.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('No exchange rate from USD to MVR', $e->getMessage());
        }

        ExchangeRate::query()->create(['from_currency' => 'USD', 'to_currency' => 'MVR', 'rate' => '15.42', 'as_of' => '2026-10-01']);
        $ledger->verify($payment->fresh(), [['currency' => 'MVR', 'amount_minor' => 1542000]]);

        $allocation = $payment->fresh()->allocations->first();
        $this->assertSame('MVR', $allocation->currency);
        $this->assertSame(1542000, $allocation->amount_minor);
        $this->assertSame('15.42000000', $allocation->rate_snapshot);
        $this->assertSame(1542000, $booking->fresh()->paidIn('MVR')->minor);
    }

    public function test_allocations_cannot_add_up_to_more_than_the_payment(): void
    {
        $booking = $this->booking();
        $ledger = app(Ledger::class);
        $payment = $ledger->record($booking, Money::ofMajor('1000', 'MVR'), Payment::CASH);

        $this->expectException(\InvalidArgumentException::class);
        $ledger->verify($payment, [['currency' => 'MVR', 'amount_minor' => 500000]]);
    }

    public function test_refusing_and_cancelling_leave_the_balance_alone(): void
    {
        $booking = $this->booking();
        $ledger = app(Ledger::class);

        $refused = $ledger->refuse($ledger->record($booking, Money::ofMajor('5000', 'MVR'), Payment::BANK_TRANSFER), 'Nothing arrived');
        $cancelled = $ledger->cancel($ledger->record($booking, Money::ofMajor('5000', 'MVR'), Payment::BANK_TRANSFER), 'Entered twice');

        $this->assertSame(Payment::FAILED, $refused->status);
        $this->assertSame('Nothing arrived', $refused->rejection_reason);
        $this->assertSame(Payment::CANCELLED, $cancelled->status);
        $this->assertSame(0, $booking->fresh()->paidIn('MVR')->minor);
        $this->assertTrue($refused->isTerminal());
    }

    public function test_a_refund_is_a_negative_payment_that_undoes_its_share_of_the_allocations(): void
    {
        $booking = $this->booking();
        $ledger = app(Ledger::class);
        $payment = $ledger->verify($ledger->record($booking, Money::ofMajor('10000', 'MVR'), Payment::CASH));

        $refund = $ledger->refund($payment, Money::ofMajor('4000', 'MVR'), 'One traveller dropped out');

        $this->assertSame(-400000, $refund->amount_minor);
        $this->assertSame($payment->id, $refund->refund_of_id);
        $this->assertSame(Payment::SUCCEEDED, $refund->status);
        $this->assertSame(-400000, $refund->allocations->first()->amount_minor);
        $this->assertSame(600000, $booking->fresh()->paidIn('MVR')->minor);

        try {
            $ledger->refund($payment->fresh(), Money::ofMajor('7000', 'MVR'));
            $this->fail('More than was left was refunded.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('MVR 6,000', $e->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $ledger->refund($refund);
    }

    public function test_recompute_is_a_sum_not_an_increment(): void
    {
        $booking = $this->booking();
        $ledger = app(Ledger::class);
        $ledger->verify($ledger->record($booking, Money::ofMajor('10000', 'MVR'), Payment::CASH));
        $ledger->verify($ledger->record($booking, Money::ofMajor('5000', 'MVR'), Payment::CASH));

        $this->assertSame(['MVR' => 1500000], $ledger->recompute($booking->fresh()));
        $this->assertSame(['MVR' => 1500000], $ledger->recompute($booking->fresh()));
        $this->assertSame(1500000, $booking->fresh()->paidIn('MVR')->minor);
    }

    public function test_an_excursion_booking_is_paid_in_riyal_and_marks_itself_paid(): void
    {
        $booking = $this->booking();
        $taif = $booking->trip->excursions()->create(['title' => 'Taif', 'is_included' => false, 'price_minor' => 15000, 'currency' => 'SAR']);
        $xb = app(Excursions::class)->book($taif, $booking, [$booking->pilgrims->first()->id]);
        $ledger = app(Ledger::class);

        $ledger->verify($ledger->record($xb, Money::ofMajor('150', 'SAR'), Payment::CASH));

        $this->assertSame(ExcursionBooking::PAID, $xb->fresh()->status);
        $this->assertSame(0, $xb->fresh()->balance()->minor);
    }

    public function test_a_payment_for_nothing_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(Ledger::class)->record($this->booking(), Money::ofMinor(0, 'MVR'), Payment::CASH);
    }
}
