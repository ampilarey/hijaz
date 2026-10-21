<?php

namespace Tests\Feature\Payments;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\PriceTier;
use App\Models\User;
use App\Services\Bookings\BookingDesk;
use App\Services\Bookings\Excursions;
use App\Services\Payments\Ledger;
use App\Support\Access;
use App\Support\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PricedTrip;
use Tests\TestCase;

class InvoicePdfTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        config(['portal.second_factor_required' => false]);
    }

    public function test_the_invoice_receipt_and_excursion_invoice_render_as_pdf_for_staff_who_may_see_them(): void
    {
        $trip = PricedTrip::make();
        [$customer, $head] = PricedTrip::family();
        $head->forceFill(['name_dv' => 'އަހްމަދު ހަސަން'])->save();
        Account::query()->create(['name' => 'BML MVR', 'kind' => Account::BANK, 'currency' => 'MVR', 'bank' => 'Bank of Maldives', 'account_name' => 'Hijaz', 'account_number' => '7730000012345678', 'show_on_invoices' => true]);
        $booking = app(BookingDesk::class)->open($trip, $customer, [['pilgrim_id' => $head->id, 'occupancy' => PriceTier::SINGLE]]);
        $booking->schedule()->create(['label' => 'Deposit', 'currency' => 'USD', 'amount_minor' => 10000, 'due_on' => now()->addDays(7)]);
        $ledger = app(Ledger::class);
        $payment = $ledger->verify($ledger->record($booking, Money::ofMajor('100', 'USD'), Payment::CASH));
        $taif = $trip->excursions()->create(['title' => 'Taif', 'is_included' => false, 'price_minor' => 15000, 'currency' => 'SAR']);
        $xb = app(Excursions::class)->book($taif, $booking, [$booking->pilgrims->first()->id]);

        $this->get(route('bookings.invoice', $booking))->assertRedirect();

        $finance = User::factory()->create()->assignRole(Access::FINANCE);

        $this->actingAs($finance)->get(route('bookings.invoice', $booking))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->actingAs($finance)->get(route('payments.receipt', $payment))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->actingAs($finance)->get(route('excursion-bookings.invoice', $xb))->assertOk()->assertHeader('content-type', 'application/pdf');

        $this->assertSame(3, AuditLog::query()->where('event', AuditLog::DOWNLOADED)->count());

        $leader = User::factory()->create()->assignRole(Access::GROUP_LEADER);
        $this->actingAs($leader)->get(route('payments.receipt', $payment))->assertForbidden();
    }

    public function test_an_unverified_payment_has_no_receipt(): void
    {
        $trip = PricedTrip::make();
        [$customer, $head] = PricedTrip::family();
        $booking = app(BookingDesk::class)->open($trip, $customer, [['pilgrim_id' => $head->id, 'occupancy' => PriceTier::QUAD]]);
        $payment = app(Ledger::class)->record($booking, Money::ofMajor('100', 'MVR'), Payment::CASH);

        $this->actingAs(User::factory()->create()->assignRole(Access::ADMIN))->get(route('payments.receipt', $payment))->assertNotFound();
    }
}
