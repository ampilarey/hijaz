<?php

namespace Tests\Feature\Ground;

use App\Filament\Resources\Bookings\Pages\ViewBooking;
use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Models\Agreement;
use App\Models\Booking;
use App\Models\Document;
use App\Models\PriceTier;
use App\Models\User;
use App\Services\Bookings\BookingDesk;
use App\Services\SelfService\Gatekeeper;
use App\Support\Access;
use App\Support\CustomerShare;
use App\Support\Phone;
use App\Support\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\Images;
use Tests\Support\PricedTrip;
use Tests\TestCase;

/** The customer portal: in by the office's link, a password for next time, the agreement signed, the letters downloaded. */
class CustomerPortalTest extends TestCase
{
    use RefreshDatabase;

    /** A 1x1 transparent PNG, as a signature pad would send it. */
    private const SIGNATURE = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        $this->seed(RolesAndPermissionsSeeder::class);
        config(['portal.second_factor_required' => false, 'documents.reader' => 'manual']);
    }

    private function booking(): Booking
    {
        $trip = PricedTrip::make();
        [$customer, $head, $spouse] = PricedTrip::family();
        $customer->forceFill(['phone' => '7771234'])->save();

        return app(BookingDesk::class)->open($trip, $customer, [['pilgrim_id' => $head->id, 'occupancy' => PriceTier::QUAD], ['pilgrim_id' => $spouse->id, 'occupancy' => PriceTier::QUAD]]);
    }

    private function enter(Booking $booking): void
    {
        $this->get(app(Gatekeeper::class)->issue($booking)['url'])->assertRedirect(route('my.home'));
    }

    public function test_a_customer_sets_a_password_from_the_link_and_signs_in_with_their_mobile_after(): void
    {
        $booking = $this->booking();
        $this->enter($booking);
        $this->get(route('my.home'))->assertOk()->assertSee('Set a password');

        $this->post(route('my.password'), ['password' => 'umrah2027', 'password_confirmation' => 'umrah2027'])->assertRedirect();
        $this->assertNotNull($booking->customer->fresh()->portal_password_set_at);
        $this->assertDatabaseMissing('audit_logs', ['new_values' => json_encode(['portal_password' => $booking->customer->fresh()->portal_password])]);

        $this->post(route('my.leave'));
        $this->get(route('my.home'))->assertRedirect(route('my.locked'));

        $this->post(route('my.login'), ['phone' => '777 1234', 'password' => 'wrong-one'])->assertSessionHas('problem');
        $this->get(route('my.home'))->assertRedirect(route('my.locked'));

        $this->post(route('my.login'), ['phone' => '+960 777 1234', 'password' => 'umrah2027'])->assertRedirect(route('my.home'));
        $this->get(route('my.home'))->assertOk()->assertSee($booking->reference)->assertSee('Change your password');
    }

    public function test_a_signed_in_customer_switches_between_their_bookings_and_never_to_anyone_elses(): void
    {
        $first = $this->booking();
        $trip = PricedTrip::make();
        $second = app(BookingDesk::class)->open($trip, $first->customer, [['pilgrim_id' => $first->pilgrims->first()->pilgrim_id, 'occupancy' => PriceTier::QUAD]]);
        $stranger = $this->booking();

        $first->customer->forceFill(['portal_password' => bcrypt('umrah2027')])->save();
        $this->post(route('my.login'), ['phone' => '7771234', 'password' => 'umrah2027']);

        $this->get(route('my.home'))->assertOk()->assertSee($second->reference)->assertSee($first->reference);
        $this->post(route('my.choose'), ['booking_id' => $first->id])->assertRedirect(route('my.home'));
        $this->get(route('my.home'))->assertSee('Your bookings');
        $this->post(route('my.choose'), ['booking_id' => $stranger->id])->assertNotFound();
    }

    public function test_the_customer_signs_the_agreement_on_screen_and_its_wording_is_kept(): void
    {
        $booking = $this->booking();
        $this->enter($booking);

        $this->get(route('my.agreement'))->assertOk()->assertSee($booking->reference)->assertSee('Sign the agreement');
        $this->post(route('my.agreement.sign'), ['name' => 'AHMED HASSAN', 'signature' => 'not-a-picture', 'agree' => '1'])->assertSessionHas('problem');
        $this->post(route('my.agreement.sign'), ['name' => 'AHMED HASSAN', 'signature' => self::SIGNATURE])->assertSessionHasErrors('agree');

        $this->post(route('my.agreement.sign'), ['name' => 'AHMED HASSAN', 'signature' => self::SIGNATURE, 'agree' => '1'])->assertRedirect(route('my.home'));

        $agreement = Agreement::query()->firstOrFail();
        $this->assertTrue($agreement->isSigned());
        $this->assertSame(Agreement::DIGITAL, $agreement->method);
        $this->assertSame('AHMED HASSAN', $agreement->signed_name);
        $this->assertStringContainsString($booking->reference, $agreement->terms);

        Settings::set(Settings::AGREEMENT_TERMS, 'Completely new wording for {booking}.');
        $agreement->refreshWording();
        $this->assertStringNotContainsString('Completely new wording', $agreement->fresh()->terms);

        $this->get(route('my.agreement.pdf'))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->post(route('my.agreement.sign'), ['name' => 'AHMED HASSAN', 'signature' => self::SIGNATURE, 'agree' => '1'])->assertSessionHas('problem');
    }

    public function test_a_signed_paper_copy_from_the_customer_or_the_office_counts(): void
    {
        $booking = $this->booking();
        $this->enter($booking);
        $this->post(route('my.upload'), ['kind' => 'agreement', 'file' => UploadedFile::fake()->createWithContent('signed.jpg', Images::photo())])->assertSessionHas('done');

        $agreement = Agreement::query()->firstOrFail();
        $this->assertSame(Agreement::SCAN, $agreement->method);
        $this->assertNotNull($booking->documents()->where('type', Document::AGREEMENT)->first()?->currentVersion());

        $other = $this->booking();
        $this->actingAs(User::factory()->create()->assignRole(Access::REGISTRATION));
        Livewire::test(ViewBooking::class, ['record' => $other->getKey()])
            ->callAction('agreement_scan', ['scan' => UploadedFile::fake()->createWithContent('paper.jpg', Images::photo())])
            ->assertNotified();
        $this->assertSame(Agreement::SCAN, Agreement::for($other)->method);
        $this->get(route('bookings.agreement', $other))->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_travel_letters_come_once_the_booking_is_confirmed_and_the_flight_is_set(): void
    {
        $booking = $this->booking();
        $this->enter($booking);
        $this->get(route('my.home'))->assertSee('appears here once the booking is confirmed');

        app(BookingDesk::class)->confirm($booking);
        $booking->trip->flights()->create(['direction' => 'outbound', 'airline' => 'Saudia', 'flight_number' => 'SV 789', 'from_airport' => 'MLE', 'to_airport' => 'JED', 'departs_at' => now()->addMonth()->setTime(23, 40)]);

        $bp = $booking->pilgrims()->first();
        $this->get(route('my.home'))->assertOk()->assertSee('SV 789')->assertSee('All letters in one PDF');
        $this->get(route('my.letters', $bp))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->get(route('my.letters'))->assertOk();

        $stranger = $this->booking();
        $this->get(route('my.letters', $stranger->pilgrims()->first()))->assertNotFound();

        $this->actingAs(User::factory()->create()->assignRole(Access::REGISTRATION));
        $this->get(route('booking-pilgrims.letter', $bp))->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_the_office_sends_the_portal_by_whatsapp_viber_or_telegram(): void
    {
        $booking = $this->booking();
        $share = CustomerShare::for($booking, 'https://example.test/my/enter/abc');
        $headPhone = ltrim((string) \App\Support\Phone::normalise($booking->customer->head->phone), '+');

        // With no leader named, the head of family is the contact, on their own mobile.
        $this->assertStringStartsWith('https://wa.me/'.$headPhone.'?text=', $share['whatsapp']);
        $this->assertStringContainsString(rawurlencode($booking->reference), $share['whatsapp']);
        $this->assertStringStartsWith('viber://forward?text=', $share['viber']);
        $this->assertStringStartsWith('https://t.me/share/url?url='.rawurlencode('https://example.test/my/enter/abc'), $share['telegram']);

        $this->actingAs(User::factory()->create()->assignRole(Access::REGISTRATION));
        Livewire::test(ViewBooking::class, ['record' => $booking->getKey()])->callAction('share')->assertNotified('Link made: send it');
    }

    public function test_the_family_leader_is_the_contact_for_messages_sign_in_the_agreement_and_the_lead(): void
    {
        $trip = PricedTrip::make();
        [$customer, $head, $son] = PricedTrip::family();
        $customer->forceFill(['phone' => '7770000'])->save();
        $son->forceFill(['phone' => '9605551'])->save();

        $this->actingAs(User::factory()->create()->assignRole(Access::REGISTRATION));
        Livewire::test(EditCustomer::class, ['record' => $customer->getRouteKey()])
            ->fillForm(['contact_pilgrim_id' => $son->id])
            ->call('save')
            ->assertHasNoFormErrors();
        $customer->refresh();
        $this->assertSame($son->id, $customer->contact_pilgrim_id);
        $this->assertSame('+9609605551', Phone::normalise($customer->contactPhone()));

        $booking = app(BookingDesk::class)->open($trip, $customer, [['pilgrim_id' => $head->id, 'occupancy' => PriceTier::QUAD], ['pilgrim_id' => $son->id, 'occupancy' => PriceTier::QUAD]]);
        $this->assertTrue($booking->pilgrims->firstWhere('pilgrim_id', $son->id)->is_lead);

        $share = CustomerShare::for($booking, 'https://example.test/my/enter/abc');
        $this->assertStringStartsWith('https://wa.me/9609605551?', $share['whatsapp']);
        $this->assertStringContainsString($son->name_en, $share['message']);

        // The contact's own mobile signs in, as does the family phone.
        $customer->forceFill(['portal_password' => bcrypt('umrah2027')])->save();
        auth()->logout();
        $this->post(route('my.login'), ['phone' => '9605551', 'password' => 'umrah2027'])->assertRedirect(route('my.home'));
        $this->get(route('my.agreement'))->assertSee('value="'.$son->name_en.'"', false);
        $this->post(route('my.leave'));
        $this->post(route('my.login'), ['phone' => '7770000', 'password' => 'umrah2027'])->assertRedirect(route('my.home'));

        // One booking can name someone else in charge.
        $booking->update(['contact_pilgrim_id' => $head->id]);
        $this->assertSame($head->name_en, $booking->fresh()->contactName());
        $this->assertSame($son->name_en, $customer->fresh()->contactName());
    }
}
