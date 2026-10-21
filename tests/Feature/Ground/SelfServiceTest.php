<?php

namespace Tests\Feature\Ground;

use App\Filament\Resources\Bookings\Pages\ViewBooking;
use App\Models\Account;
use App\Models\Document;
use App\Models\Payment;
use App\Models\PortalAccess;
use App\Models\PriceTier;
use App\Models\User;
use App\Services\Bookings\BookingDesk;
use App\Services\Payments\Ledger;
use App\Services\SelfService\Gatekeeper;
use App\Support\Access;
use App\Support\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\Images;
use Tests\Support\PricedTrip;
use Tests\TestCase;

class SelfServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        $this->seed(RolesAndPermissionsSeeder::class);
        config(['portal.second_factor_required' => false, 'documents.reader' => 'manual']);
    }

    private function booking()
    {
        $trip = PricedTrip::make();
        [$customer, $head] = PricedTrip::family();

        return app(BookingDesk::class)->open($trip, $customer, [['pilgrim_id' => $head->id, 'occupancy' => PriceTier::QUAD]]);
    }

    public function test_the_office_makes_a_link_and_the_customer_sees_their_booking_through_it(): void
    {
        $booking = $this->booking();
        Account::query()->create(['name' => 'BML', 'kind' => Account::BANK, 'currency' => 'MVR', 'bank' => 'BML', 'account_name' => 'Hijaz', 'account_number' => '7730000012345678', 'show_on_invoices' => true]);
        $ledger = app(Ledger::class);
        $payment = $ledger->verify($ledger->record($booking, Money::ofMajor('5000', 'MVR'), Payment::CASH));

        $this->actingAs(User::factory()->create()->assignRole(Access::REGISTRATION));
        Livewire::test(ViewBooking::class, ['record' => $booking->getKey()])->callAction('share')->assertNotified();
        $access = PortalAccess::firstOrFail();
        $this->assertTrue($access->isLive());

        // The URL is only in the notification; rebuild it from a fresh issue for the test.
        $issued = app(Gatekeeper::class)->issue($booking);
        auth()->logout();

        $this->get($issued['url'])->assertRedirect(route('my.home'));
        $page = $this->get(route('my.home'))->assertOk();
        $page->assertSee($booking->reference)->assertSee('MVR 23,500')->assertSee('7730000012345678')->assertSee('Receipt')->assertSee('missing');
        $this->get(route('my.invoice'))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->get(route('my.receipt', $payment))->assertOk()->assertHeader('content-type', 'application/pdf');

        $this->post(route('my.upload'), ['kind' => 'copy', 'type' => Document::PASSPORT, 'booking_pilgrim_id' => $booking->pilgrims->first()->id, 'file' => UploadedFile::fake()->createWithContent('pp.jpg', Images::photo())])->assertRedirect();
        $this->assertNotNull($booking->pilgrims->first()->pilgrim->fresh()->document(Document::PASSPORT));
        $this->assertSame('self', $booking->pilgrims->first()->pilgrim->fresh()->document(Document::PASSPORT)->currentVersion()->channel);

        $this->post(route('my.upload'), ['kind' => 'slip', 'amount' => '1000', 'currency' => 'MVR', 'file' => UploadedFile::fake()->createWithContent('slip.jpg', Images::photo())])->assertRedirect();
        $slip = Payment::query()->where('status', Payment::AWAITING_REVIEW)->firstOrFail();
        $this->assertSame(100000, $slip->amount_minor);
        $this->assertSame('self', $slip->channel);

        $this->post(route('my.leave'))->assertRedirect(route('my.locked'));
        $this->get(route('my.home'))->assertRedirect(route('my.locked'));
    }

    public function test_bad_expired_and_cancelled_links_are_turned_away(): void
    {
        $booking = $this->booking();
        $issued = app(Gatekeeper::class)->issue($booking);

        $this->get(route('my.enter', ['token' => 'notatoken']))->assertRedirect(route('my.locked'));
        $this->get(route('my.enter', ['token' => str_repeat('a', 48)]))->assertRedirect(route('my.locked'));

        app(Gatekeeper::class)->revokeAll($booking);
        $this->get($issued['url'])->assertRedirect(route('my.locked'));
        $this->get(route('my.home'))->assertRedirect(route('my.locked'));

        $fresh = app(Gatekeeper::class)->issue($booking);
        $this->travel(31)->days();
        $this->get($fresh['url'])->assertRedirect(route('my.locked'));
        $this->get(route('my.locked'))->assertOk()->assertSee('expired');

        $this->get(route('my.receipt', 1))->assertNotFound();
    }
}
