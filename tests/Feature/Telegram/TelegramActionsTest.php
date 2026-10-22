<?php

namespace Tests\Feature\Telegram;

use App\Events\ReadingProposed;
use App\Models\Document;
use App\Models\DocumentExtraction;
use App\Models\ExchangeRate;
use App\Models\Payment;
use App\Models\Pilgrim;
use App\Models\PriceTier;
use App\Models\TelegramLink;
use App\Models\User;
use App\Services\Bookings\BookingDesk;
use App\Services\Documents\DocumentWallet;
use App\Services\Payments\Ledger;
use App\Services\Pilgrims\Acceptor;
use App\Services\Telegram\Bot;
use App\Services\Telegram\Linker;
use App\Support\Access;
use App\Support\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Images;
use Tests\Support\PricedTrip;
use Tests\TestCase;

class TelegramActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        $this->seed(RolesAndPermissionsSeeder::class);
        config(['telegram.bot_token' => '123456:TEST', 'portal.second_factor_required' => false, 'documents.reader' => 'manual']);
        Http::fake([
            'api.telegram.org/file/*' => Http::response(Images::photo()),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1, 'file_path' => 'photos/x.jpg']]),
        ]);
    }

    private function linked(string $role, int $chat): User
    {
        $user = User::factory()->create()->assignRole($role);
        app(Linker::class)->redeem(app(Linker::class)->issueCode($user), $chat);

        return $user;
    }

    private function send(int $id, int $chat, string $text, array $extra = [])
    {
        return $this->postJson('/api/telegram/webhook', [
            'update_id' => $id,
            'message' => array_merge(['message_id' => $id, 'chat' => ['id' => $chat, 'type' => 'private'], 'from' => ['id' => $chat, 'first_name' => 'T'], 'text' => $text], $extra),
        ], ['X-Telegram-Bot-Api-Secret-Token' => Bot::webhookSecret()])->assertOk();
    }

    private function tap(int $id, int $chat, string $data, int $messageId = 77)
    {
        return $this->postJson('/api/telegram/webhook', [
            'update_id' => $id,
            'callback_query' => ['id' => 'cq'.$id, 'from' => ['id' => $chat], 'data' => $data, 'message' => ['message_id' => $messageId, 'chat' => ['id' => $chat], 'text' => 'original']],
        ], ['X-Telegram-Bot-Api-Secret-Token' => Bot::webhookSecret()])->assertOk();
    }

    /** @return list<array{method: string, body: array<string, mixed>}> */
    private function calls(): array
    {
        $calls = [];
        Http::assertSent(function ($request) use (&$calls): bool {
            $calls[] = ['method' => basename($request->url()), 'body' => $request->data()];

            return true;
        });

        return $calls;
    }

    private function booking()
    {
        $trip = PricedTrip::make();
        [$customer, $head] = PricedTrip::family();

        return app(BookingDesk::class)->open($trip, $customer, [['pilgrim_id' => $head->id, 'occupancy' => PriceTier::QUAD]]);
    }

    public function test_finance_verifies_a_payment_from_the_alert_in_two_taps(): void
    {
        $finance = $this->linked(Access::FINANCE, 700);
        $this->linked(Access::REGISTRATION, 701);
        $booking = $this->booking();
        $payment = app(Ledger::class)->record($booking, Money::ofMajor('10000', 'MVR'), Payment::CASH);

        $alert = collect($this->calls())->first(fn (array $c): bool => $c['method'] === 'sendMessage' && ($c['body']['chat_id'] ?? null) === 700 && str_contains($c['body']['text'], 'Payment to verify'));
        $this->assertSame('pay.verify:'.$payment->id, $alert['body']['reply_markup']['inline_keyboard'][0][0]['callback_data']);

        $this->tap(10, 701, 'pay.verify:'.$payment->id);
        $this->assertSame(Payment::AWAITING_REVIEW, $payment->fresh()->status);
        $this->assertTrue(collect($this->calls())->contains(fn (array $c): bool => $c['method'] === 'answerCallbackQuery' && str_contains($c['body']['text'] ?? '', 'cannot verify')));

        $this->tap(11, 700, 'pay.verify:'.$payment->id);
        $confirm = collect($this->calls())->last(fn (array $c): bool => $c['method'] === 'editMessageText');
        $this->assertStringContainsString('Verify '.$payment->reference.'?', $confirm['body']['text']);
        $this->assertSame('pay.verify.yes:'.$payment->id, $confirm['body']['reply_markup']['inline_keyboard'][0][0]['callback_data']);

        $this->tap(12, 700, 'pay.verify.yes:'.$payment->id);
        $this->assertSame(Payment::SUCCEEDED, $payment->fresh()->status);
        $this->assertTrue($payment->fresh()->reviewer->is($finance));
        $this->assertSame(1000000, $booking->fresh()->paidIn('MVR')->minor);
        $this->assertStringContainsString('verified</b> by '.e($finance->name), collect($this->calls())->last(fn (array $c): bool => $c['method'] === 'editMessageText')['body']['text']);

        $this->tap(13, 700, 'pay.verify.yes:'.$payment->id);
        $this->assertTrue(collect($this->calls())->contains(fn (array $c): bool => $c['method'] === 'answerCallbackQuery' && str_contains($c['body']['text'] ?? '', 'no longer waiting')));
    }

    public function test_refusing_asks_for_the_reason_in_the_next_message(): void
    {
        $this->linked(Access::FINANCE, 700);
        $booking = $this->booking();
        $payment = app(Ledger::class)->record($booking, Money::ofMajor('10000', 'MVR'), Payment::BANK_TRANSFER);

        $this->tap(20, 700, 'pay.refuse:'.$payment->id);
        $this->assertSame('pay.refuse', TelegramLink::query()->where('chat_id', 700)->first()->pending['action']);

        $this->send(21, 700, 'Nothing arrived in BML by Friday');
        $this->assertSame(Payment::FAILED, $payment->fresh()->status);
        $this->assertSame('Nothing arrived in BML by Friday', $payment->fresh()->rejection_reason);
        $this->assertNull(TelegramLink::query()->where('chat_id', 700)->first()->pending);
    }

    public function test_a_proposed_reading_is_announced_and_accepted_by_tap(): void
    {
        $registration = $this->linked(Access::REGISTRATION, 800);
        $this->linked(Access::OPERATIONS, 801);
        $pilgrim = Pilgrim::factory()->create(['name_en' => 'OLD NAME']);
        $version = app(DocumentWallet::class)->store($pilgrim, Images::photo(), 'pp.jpg', 'image/jpeg', Document::PASSPORT);
        $x = $version->extractions()->create(['reader' => 'tesseract', 'fields' => ['passport_number' => 'P1234567', 'passport_expires_on' => '2032-01-01', 'name_en' => 'NEW NAME'], 'confidence' => ['passport_number' => 0.97], 'mrz_valid' => true, 'status' => DocumentExtraction::PROPOSED]);
        ReadingProposed::dispatch($x, null);

        $alerts = collect($this->calls())->filter(fn (array $c): bool => $c['method'] === 'sendMessage' && str_contains($c['body']['text'] ?? '', 'Reading to accept'));
        $this->assertEqualsCanonicalizing([800, 801], $alerts->pluck('body.chat_id')->all());
        $this->assertSame('doc.accept:'.$x->id, $alerts->first()['body']['reply_markup']['inline_keyboard'][0][0]['callback_data']);

        $this->tap(30, 800, 'doc.accept:'.$x->id);
        $confirm = collect($this->calls())->last(fn (array $c): bool => $c['method'] === 'editMessageText');
        $this->assertStringContainsString('Passport number: P1234567', $confirm['body']['text']);

        $this->tap(31, 800, 'doc.accept.yes:'.$x->id);
        $this->assertSame(DocumentExtraction::ACCEPTED, $x->fresh()->status);
        $this->assertSame('P1234567', $pilgrim->fresh()->passport_number);
        $this->assertSame('NEW NAME', $pilgrim->fresh()->name_en);
        $this->assertTrue($x->fresh()->reviewer->is($registration));
    }

    public function test_a_failed_mrz_gets_no_accept_button_and_a_mismatch_is_announced(): void
    {
        $this->linked(Access::OPERATIONS, 801);
        $pilgrim = Pilgrim::factory()->create(['name_en' => 'AHMED HASSAN', 'gender' => 'male']);
        $idVersion = app(DocumentWallet::class)->store($pilgrim, Images::photo(), 'id.jpg', 'image/jpeg', Document::ID_CARD);
        $idX = $idVersion->extractions()->create(['reader' => 'tesseract', 'fields' => ['name_en' => 'AHMED HASSAN', 'gender' => 'male', 'date_of_birth' => '1980-01-01'], 'status' => DocumentExtraction::PROPOSED]);
        app(Acceptor::class)->accept($idX, $idX->fields);

        $ppVersion = app(DocumentWallet::class)->store($pilgrim, Images::photo(), 'pp.jpg', 'image/jpeg', Document::PASSPORT);
        $ppX = $ppVersion->extractions()->create(['reader' => 'tesseract', 'fields' => ['passport_number' => 'P1', 'date_of_birth' => '1981-05-05'], 'mrz_valid' => false, 'status' => DocumentExtraction::PROPOSED]);
        ReadingProposed::dispatch($ppX, null);
        $alert = collect($this->calls())->last(fn (array $c): bool => $c['method'] === 'sendMessage' && str_contains($c['body']['text'] ?? '', 'Reading to accept'));
        $this->assertStringContainsString('FAIL', $alert['body']['text']);
        $this->assertArrayNotHasKey('reply_markup', $alert['body']);

        app(Acceptor::class)->accept($ppX, $ppX->fields);
        $mismatch = collect($this->calls())->last(fn (array $c): bool => $c['method'] === 'sendMessage' && str_contains($c['body']['text'] ?? '', 'disagree'));
        $this->assertSame(801, $mismatch['body']['chat_id']);
        $this->assertStringContainsString('Date of birth: ID card says 1980-01-01, passport says 1981-05-05', $mismatch['body']['text']);
    }

    public function test_the_checklist_is_ticked_by_tap_and_rates_are_listed(): void
    {
        $ops = $this->linked(Access::OPERATIONS, 900);
        $trip = PricedTrip::make();
        $item = $trip->checklist()->create(['title' => 'Book the coaches', 'due_on' => now()->subDay()]);
        ExchangeRate::query()->create(['from_currency' => 'USD', 'to_currency' => 'MVR', 'rate' => '15.42', 'as_of' => today()]);

        $this->send(40, 900, '/checklist');
        $list = collect($this->calls())->last(fn (array $c): bool => $c['method'] === 'sendMessage');
        $this->assertSame('check.tick:'.$item->id, $list['body']['reply_markup']['inline_keyboard'][0][0]['callback_data']);

        $this->tap(41, 900, 'check.tick:'.$item->id);
        $this->tap(42, 900, 'check.tick.yes:'.$item->id);
        $this->assertTrue($item->fresh()->isDone());
        $this->assertTrue($item->fresh()->doneBy->is($ops));

        $this->send(43, 900, '/rates');
        $this->assertStringContainsString('1 USD = 15.42 MVR', collect($this->calls())->last(fn (array $c): bool => $c['method'] === 'sendMessage')['body']['text']);
    }

    public function test_lists_invoices_and_receipts_arrive_as_pdf_files(): void
    {
        $this->linked(Access::OPERATIONS, 950);
        $booking = $this->booking();
        $trip = $booking->trip;
        app(BookingDesk::class)->confirm($booking);
        $bus = $trip->busGroups()->create(['name' => 'Bus 1']);
        $booking->pilgrims()->update(['bus_group_id' => $bus->id]);
        $trip->hotels()->create(['city' => 'makkah', 'name' => 'Swissotel', 'check_in_on' => $trip->departs_on, 'check_out_on' => $trip->returns_on]);
        $ledger = app(Ledger::class);
        $payment = $ledger->verify($ledger->record($booking, Money::ofMajor('1000', 'MVR'), Payment::CASH));

        $this->send(50, 950, '/bus 1');
        $this->send(51, 950, '/room makkah hotel');
        $this->send(52, 950, '/manifest airline');
        $this->send(53, 950, '/invoice '.strtolower(str_replace('-', '', $booking->reference)));
        $this->send(54, 950, '/receipt '.$payment->reference);

        $captions = [];
        Http::assertSent(function ($request) use (&$captions): bool {
            if (str_ends_with($request->url(), '/sendDocument')) {
                $parts = collect($request->data());
                $captions[] = (string) ($parts->firstWhere('name', 'caption')['contents'] ?? '');
                $this->assertStringStartsWith('%PDF', (string) ($parts->firstWhere('name', 'document')['contents'] ?? ''));
            }

            return true;
        });

        $this->assertCount(5, $captions);
        $this->assertStringContainsString('Bus 1', $captions[0]);
        $this->assertStringContainsString('hotel copy', $captions[1]);
        $this->assertStringContainsString('Airline list', $captions[2]);
        $this->assertStringContainsString($booking->reference, $captions[3]);
        $this->assertStringContainsString('forward this to the customer', $captions[4]);
    }

    public function test_a_slip_photo_with_the_booking_reference_records_the_payment(): void
    {
        $this->linked(Access::REGISTRATION, 960);
        $this->linked(Access::FINANCE, 961);
        $booking = $this->booking();

        $this->send(60, 960, $booking->reference.' MVR 12,500', ['photo' => [['file_id' => 'small', 'file_unique_id' => 'u1'], ['file_id' => 'big', 'file_unique_id' => 'u2']]]);

        $payment = Payment::firstOrFail();
        $this->assertTrue($payment->payable->is($booking));
        $this->assertSame(1250000, $payment->amount_minor);
        $this->assertSame(Payment::AWAITING_REVIEW, $payment->status);
        $this->assertSame('telegram', $payment->channel);
        $this->assertNotNull($payment->slip()?->currentVersion());
        $this->assertTrue(collect($this->calls())->contains(fn (array $c): bool => $c['method'] === 'sendMessage' && ($c['body']['chat_id'] ?? null) === 961 && str_contains($c['body']['text'], 'Payment to verify')));
        $this->assertTrue(collect($this->calls())->contains(fn (array $c): bool => $c['method'] === 'sendMessage' && ($c['body']['chat_id'] ?? null) === 960 && str_contains($c['body']['text'], $payment->reference.'</b> recorded')));

        $this->send(61, 960, $booking->reference, ['photo' => [['file_id' => 'big', 'file_unique_id' => 'u3']]]);
        $this->assertSame(1, Payment::count(), 'without an amount in the caption and nothing readable, nothing is recorded');
        $this->assertTrue(collect($this->calls())->contains(fn (array $c): bool => $c['method'] === 'sendMessage' && str_contains($c['body']['text'] ?? '', 'Put the amount in the caption')));
    }
}
