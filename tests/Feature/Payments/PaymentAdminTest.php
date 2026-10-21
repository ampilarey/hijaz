<?php

namespace Tests\Feature\Payments;

use App\Filament\Resources\Bookings\Pages\ViewBooking;
use App\Filament\Resources\Bookings\RelationManagers\PaymentsRelationManager;
use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Filament\Resources\Payments\PaymentResource;
use App\Models\Booking;
use App\Models\Document;
use App\Models\ExchangeRate;
use App\Models\Payment;
use App\Models\PriceTier;
use App\Models\User;
use App\Services\Bookings\BookingDesk;
use App\Services\Payments\Ledger;
use App\Services\Telegram\Bot;
use App\Services\Telegram\Linker;
use App\Support\Access;
use App\Support\Money;
use Barryvdh\DomPDF\Facade\Pdf;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\Images;
use Tests\Support\PricedTrip;
use Tests\TestCase;

class PaymentAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        $this->seed(RolesAndPermissionsSeeder::class);
        config(['portal.second_factor_required' => false, 'documents.reader' => 'manual']);
    }

    private function staff(string $role): User
    {
        return User::factory()->create()->assignRole($role);
    }

    private function booking(): Booking
    {
        $trip = PricedTrip::make();
        [$customer, $head] = PricedTrip::family();

        return app(BookingDesk::class)->open($trip, $customer, [['pilgrim_id' => $head->id, 'occupancy' => PriceTier::QUAD]]);
    }

    private function telegramOn(): void
    {
        config(['telegram.bot_token' => '123456:TEST', 'telegram.bot_username' => 'hijaz_test_bot']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
    }

    public function test_registration_records_a_transfer_with_its_slip_and_finance_hears_of_it_on_telegram(): void
    {
        $this->telegramOn();
        $finance = $this->staff(Access::FINANCE);
        $registration = $this->staff(Access::REGISTRATION);
        app(Linker::class)->redeem(app(Linker::class)->issueCode($finance), 700);
        app(Linker::class)->redeem(app(Linker::class)->issueCode($registration), 701);
        $booking = $this->booking();

        $this->actingAs($registration);
        Livewire::test(PaymentsRelationManager::class, ['ownerRecord' => $booking, 'pageClass' => ViewBooking::class])
            ->callTableAction('record_payment', data: [
                'method' => Payment::BANK_TRANSFER,
                'currency' => 'MVR',
                'amount_minor' => '10000',
                'paid_on' => today()->toDateString(),
                'payer_reference' => 'BML77',
                'slip' => UploadedFile::fake()->createWithContent('slip.jpg', Images::photo()),
            ])
            ->assertHasNoTableActionErrors();

        $payment = Payment::firstOrFail();
        $this->assertSame(Payment::AWAITING_REVIEW, $payment->status);
        $this->assertSame(1000000, $payment->amount_minor);
        $this->assertSame('BML77', $payment->payer_reference);
        $this->assertSame($registration->id, $payment->recorded_by);
        $this->assertSame(Document::BANK_SLIP, $payment->slip()?->type);
        $this->assertSame(1, $payment->slip()->versions()->count());
        $this->assertTrue($payment->transactions()->where('type', 'slip_uploaded')->exists());

        Http::assertSent(fn ($r): bool => str_ends_with($r->url(), '/sendMessage') && $r['chat_id'] === 700 && str_contains($r['text'], 'Payment to verify') && str_contains($r['text'], $payment->reference));
        Http::assertNotSent(fn ($r): bool => str_ends_with($r->url(), '/sendMessage') && $r['chat_id'] === 701);

        $this->actingAs($finance)->get('/portal')->assertOk()->assertSee('1 payment(s) to verify');
    }

    public function test_finance_verifies_from_the_queue_and_registration_cannot(): void
    {
        $booking = $this->booking();
        $payment = app(Ledger::class)->record($booking, Money::ofMajor('10000', 'MVR'), Payment::CASH);

        $this->actingAs($this->staff(Access::REGISTRATION));
        Livewire::test(ListPayments::class)->assertTableActionHidden('verify', $payment);

        $this->actingAs($this->staff(Access::FINANCE));
        Livewire::test(ListPayments::class)
            ->assertCanSeeTableRecords([$payment])
            ->callTableAction('verify', $payment, data: ['allocations' => [['currency' => 'MVR', 'amount_minor' => '10000']]])
            ->assertHasNoTableActionErrors();

        $this->assertSame(Payment::SUCCEEDED, $payment->fresh()->status);
        $this->assertSame(1000000, $booking->fresh()->paidIn('MVR')->minor);
        $this->actingAs($this->staff(Access::FINANCE))->get(PaymentResource::getUrl())->assertOk();
    }

    public function test_dollars_settle_a_rufiyaa_balance_at_the_rate_of_the_day(): void
    {
        $booking = $this->booking();
        $payment = app(Ledger::class)->record($booking, Money::ofMajor('1000', 'USD'), Payment::BANK_TRANSFER, ['paid_on' => today()->toDateString()]);
        ExchangeRate::query()->create(['from_currency' => 'USD', 'to_currency' => 'MVR', 'rate' => '15.42', 'as_of' => today()->subDay()]);

        $this->actingAs($this->staff(Access::FINANCE));
        Livewire::test(ListPayments::class)
            ->callTableAction('verify', $payment, data: ['allocations' => [['currency' => 'MVR', 'amount_minor' => '15420']]])
            ->assertHasNoTableActionErrors();

        $this->assertSame(1542000, $booking->fresh()->paidIn('MVR')->minor);
        $this->assertSame('15.42000000', $payment->fresh()->allocations->first()->rate_snapshot);
    }

    public function test_a_slip_with_the_reference_on_it_starts_the_payment_by_itself(): void
    {
        $booking = $this->booking();
        $slip = Pdf::loadHTML('<p>Bank of Maldives transfer</p><p>Amount: MVR 12,500.00</p><p>Reference: TXN445566</p><p>Remarks: '.$booking->reference.' umrah</p><p>Date: 12/10/2026</p>')->output();

        $this->actingAs($this->staff(Access::REGISTRATION));
        Livewire::test(ListPayments::class)
            ->callTableAction('from_slip', data: ['slip' => UploadedFile::fake()->createWithContent('slip.pdf', $slip)])
            ->assertHasNoTableActionErrors();

        $payment = Payment::firstOrFail();
        $this->assertTrue($payment->payable->is($booking));
        $this->assertSame(1250000, $payment->amount_minor);
        $this->assertSame('MVR', $payment->currency);
        $this->assertSame('TXN445566', $payment->payer_reference);
        $this->assertSame('2026-10-12', $payment->paid_on->toDateString());
        $this->assertSame('pdf-text', $payment->slip()->latestExtraction()->reader);
    }

    public function test_finance_refunds_part_of_a_verified_payment_and_the_receipt_is_there(): void
    {
        $booking = $this->booking();
        $ledger = app(Ledger::class);
        $payment = $ledger->verify($ledger->record($booking, Money::ofMajor('10000', 'MVR'), Payment::CASH));

        $this->actingAs($this->staff(Access::FINANCE));
        Livewire::test(ListPayments::class)
            ->set('activeTab', 'verified')
            ->assertCanSeeTableRecords([$payment])
            ->assertTableActionHasUrl('receipt', route('payments.receipt', $payment), $payment)
            ->callTableAction('refund', $payment, data: ['amount_minor' => '2500', 'reason' => 'Agreed'])
            ->assertHasNoTableActionErrors();

        $refund = Payment::query()->where('refund_of_id', $payment->id)->firstOrFail();
        $this->assertSame(-250000, $refund->amount_minor);
        $this->assertSame(750000, $booking->fresh()->paidIn('MVR')->minor);

        $this->actingAs($this->staff(Access::OPERATIONS));
        Livewire::test(ListPayments::class)->set('activeTab', 'verified')->assertTableActionHidden('refund', $payment);
    }

    public function test_telegram_answers_balance_and_slips(): void
    {
        $this->telegramOn();
        $finance = $this->staff(Access::FINANCE);
        app(Linker::class)->redeem(app(Linker::class)->issueCode($finance), 800);
        $booking = $this->booking();
        app(Ledger::class)->record($booking, Money::ofMajor('5000', 'MVR'), Payment::CASH);

        $deliver = fn (int $id, string $text) => $this->postJson('/api/telegram/webhook', [
            'update_id' => $id,
            'message' => ['message_id' => $id, 'chat' => ['id' => 800, 'type' => 'private'], 'from' => ['id' => 800, 'first_name' => 'F'], 'text' => $text],
        ], ['X-Telegram-Bot-Api-Secret-Token' => Bot::webhookSecret()])->assertOk();

        $deliver(1, '/balance '.$booking->reference);
        $deliver(2, '/balance '.$booking->pilgrims->first()->pilgrim->id_number);
        $deliver(3, '/slips');
        $deliver(4, '/today');

        $texts = [];
        Http::assertSent(function ($request) use (&$texts): bool {
            if (str_ends_with($request->url(), '/sendMessage') && $request['chat_id'] === 800 && ! str_contains($request['text'], 'Payment to verify')) {
                $texts[] = $request['text'];
            }

            return true;
        });

        $this->assertStringContainsString('MVR 28,500 due', $texts[0]);
        $this->assertStringContainsString('1 payment waiting for Finance', $texts[0]);
        $this->assertStringContainsString($booking->reference, $texts[1]);
        $this->assertStringContainsString('1 payment to verify', $texts[2]);
        $this->assertStringContainsString('1 payment to verify (/slips)', $texts[3]);
    }
}
