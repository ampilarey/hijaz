<?php

namespace Tests\Feature\Operations;

use App\Models\Booking;
use App\Models\BookingPilgrim;
use App\Models\Card;
use App\Models\Document;
use App\Models\Pilgrim;
use App\Models\PriceTier;
use App\Models\Room;
use App\Models\Trip;
use App\Models\TripHotel;
use App\Services\Bookings\BookingDesk;
use App\Services\Documents\DocumentWallet;
use App\Services\Operations\Buses;
use App\Services\Operations\Cards;
use App\Services\Operations\Lists;
use App\Services\Operations\Readiness;
use App\Services\Operations\Rooming;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Images;
use Tests\Support\PricedTrip;
use Tests\TestCase;

class OperationsServicesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
    }

    /** @return array{Trip, Booking, Booking} A confirmed family of three and a confirmed single man. */
    private function trip(): array
    {
        $trip = PricedTrip::make();
        $trip->hotels()->createMany([
            ['city' => TripHotel::MAKKAH, 'name' => 'Swissotel', 'check_in_on' => $trip->departs_on, 'check_out_on' => $trip->departs_on->copy()->addDays(7)],
            ['city' => TripHotel::MADINAH, 'name' => 'Anwar', 'check_in_on' => $trip->departs_on->copy()->addDays(7), 'check_out_on' => $trip->returns_on],
        ]);
        [$customer, $head, $spouse, $child] = PricedTrip::family();
        $head->forceFill(['gender' => 'male'])->save();
        $spouse->forceFill(['gender' => 'female'])->save();
        $child->forceFill(['gender' => 'female'])->save();
        $desk = app(BookingDesk::class);
        $family = $desk->open($trip, $customer, [
            ['pilgrim_id' => $head->id, 'occupancy' => PriceTier::QUAD],
            ['pilgrim_id' => $spouse->id, 'occupancy' => PriceTier::QUAD],
            ['pilgrim_id' => $child->id, 'occupancy' => PriceTier::QUAD],
        ]);
        $desk->confirm($family);
        $man = Pilgrim::factory()->create(['gender' => 'male', 'name_en' => 'IBRAHIM MOOSA']);
        $single = $desk->open($trip, $man->customer, [['pilgrim_id' => $man->id, 'occupancy' => PriceTier::DOUBLE]]);
        $desk->confirm($single);

        return [$trip->fresh(), $family->fresh(), $single->fresh()];
    }

    public function test_buses_take_members_and_report_overfill_splits_and_the_unassigned(): void
    {
        [$trip, $family, $single] = $this->trip();
        $buses = app(Buses::class);
        $bus1 = $trip->busGroups()->create(['name' => 'Bus 1', 'capacity' => 2, 'leader_name' => 'Hassan', 'leader_phone' => '7771111']);
        $bus2 = $trip->busGroups()->create(['name' => 'Bus 2', 'capacity' => 40]);

        $result = $buses->assign($bus1, $family->pilgrims->pluck('id')->all());
        $this->assertSame(3, $result['assigned']);
        $this->assertStringContainsString('3 people for 2 seats', $result['warnings'][0]);

        $buses->assign($bus2, [$family->pilgrims->last()->id]);
        $problems = collect($buses->problemsWith($trip->fresh()));
        $this->assertTrue($problems->contains('type', Buses::SPLIT_FAMILY));
        $this->assertTrue($problems->contains('type', Buses::NO_LEADER));
        $this->assertTrue($problems->contains(fn (array $p): bool => $p['type'] === Buses::UNASSIGNED && str_contains($p['who'], 'IBRAHIM MOOSA')));

        $this->assertSame(1, $buses->unassign([$family->pilgrims->last()->id]));
        $this->assertSame(2, $bus1->fresh()->members()->count());
    }

    public function test_rooming_reports_every_mistake_and_rooms_a_family_in_one_tap(): void
    {
        [$trip, $family, $single] = $this->trip();
        $rooming = app(Rooming::class);
        $makkah = $trip->hotels->firstWhere('city', TripHotel::MAKKAH);

        $double = $makkah->rooms()->create(['label' => '101', 'capacity' => 2, 'gender' => Room::MALE]);
        $rooming->assign($double, [$single->pilgrims->first()->id, $family->pilgrims->firstWhere('is_lead', false)->id]); // a woman in the men's double

        $problems = collect($rooming->problemsWith($makkah->fresh()));
        $this->assertTrue($problems->contains('type', Rooming::MIXED_GENDER));
        $this->assertTrue($problems->contains('type', Rooming::UNROOMED));
        $this->assertFalse($problems->contains('type', Rooming::OVER_CAPACITY));

        $room = $rooming->roomFamily($family, $makkah->fresh());
        $this->assertSame(Room::FAMILY, $room->gender);
        $this->assertSame(3, $room->capacity);
        $this->assertSame(3, $room->assignments()->count());
        $this->assertSame(1, $double->fresh()->assignments()->count(), 'moving within the hotel gives the old bed up');

        $problems = collect($rooming->problemsWith($makkah->fresh()));
        $this->assertFalse($problems->contains('type', Rooming::UNROOMED));
        $this->assertFalse($problems->contains('type', Rooming::MIXED_GENDER));
        $this->assertFalse($problems->contains('type', Rooming::WRONG_OCCUPANCY), 'quad payers in a three-bed family room and a double payer in a double: nobody got less than they paid for');
    }

    public function test_the_occupancy_and_child_checks(): void
    {
        [$trip, $family, $single] = $this->trip();
        $rooming = app(Rooming::class);
        $madinah = $trip->hotels->firstWhere('city', TripHotel::MADINAH);
        $quad = $madinah->rooms()->create(['label' => '7', 'capacity' => 4, 'gender' => Room::MALE]);
        $child = $family->pilgrims->first(fn (BookingPilgrim $bp) => $bp->pax_type === PriceTier::CHILD);

        $rooming->assign($quad, [$single->pilgrims->first()->id]);
        $problems = collect($rooming->problemsWith($madinah->fresh()));
        $this->assertTrue($problems->contains(fn (array $p): bool => $p['type'] === Rooming::WRONG_OCCUPANCY && str_contains($p['detail'], 'double')));

        $rooming->assign($quad, [$child->id]);
        $problems = collect($rooming->problemsWith($madinah->fresh()));
        $this->assertTrue($problems->contains('type', Rooming::UNACCOMPANIED_CHILD), 'a stranger is not supervision');

        $undesignated = $madinah->rooms()->create(['label' => '8', 'capacity' => 2]);
        $this->assertSame(0, $rooming->unassign($madinah, [999]));
        $rooming->assign($undesignated, [$family->pilgrims->first()->id]);
        $this->assertTrue(collect($rooming->problemsWith($madinah->fresh()))->contains('type', Rooming::UNDESIGNATED));
    }

    public function test_readiness_names_what_blocks_and_what_merely_needs_attention(): void
    {
        [$trip, $family, $single] = $this->trip();
        $readiness = app(Readiness::class);

        $concerns = collect($readiness->concerns($trip));
        $areas = $concerns->pluck('area')->all();

        $this->assertContains('documents', $areas);
        $this->assertContains('visas', $areas);
        $this->assertContains('money', $areas);
        $this->assertContains('passports', $areas);
        $this->assertContains('rooms', $areas);
        $this->assertTrue($readiness->hasBlockers($trip));
        $this->assertContains('4 visas not issued', $readiness->blockers($trip));
        $this->assertNotContains('buses', $areas, 'no buses yet means nothing to check');

        // Fix the visas and the passports: the blockers that remain are the missing passports copies.
        BookingPilgrim::query()->update(['visa_status' => BookingPilgrim::VISA_ISSUED]);
        Pilgrim::query()->update(['passport_expires_on' => now()->addYears(5)->toDateString()]);
        $blockers = $readiness->blockers($trip->fresh());
        $this->assertCount(1, $blockers);
        $this->assertStringContainsString('missing copies', $blockers[0]);
    }

    public function test_lists_print_as_pdf_and_export_as_csv(): void
    {
        [$trip, $family, $single] = $this->trip();
        $lists = app(Lists::class);
        $bus = $trip->busGroups()->create(['name' => 'Bus 1', 'leader_name' => 'Hassan']);
        app(Buses::class)->assign($bus, $family->pilgrims->pluck('id')->all());
        $makkah = $trip->hotels->firstWhere('city', TripHotel::MAKKAH);
        app(Rooming::class)->roomFamily($family, $makkah);

        $manifest = $lists->manifest($trip, 'office');
        $this->assertCount(4, $manifest->rows);
        $ahmed = collect($manifest->rows)->firstWhere('name', 'AHMED HASSAN');
        $this->assertSame('Bus 1', $ahmed['bus']);
        $this->assertSame('F1', $ahmed['room_'.$makkah->id]);
        $this->assertStringContainsString('AHMED HASSAN', $manifest->csv());
        $this->assertStringStartsWith("\xEF\xBB\xBF#,Name", $manifest->csv());

        $airline = $lists->manifest($trip, 'airline');
        $this->assertArrayHasKey('ticket', $airline->columns);
        $this->assertArrayNotHasKey('phone', $airline->columns);

        $busList = $lists->busList($bus);
        $this->assertCount(3, $busList->rows);
        $this->assertSame('☐', $busList->rows[0]['tick']);

        $hotelCopy = $lists->roomList($makkah, forHotel: true);
        $this->assertArrayNotHasKey('phone', $hotelCopy->columns);
        $this->assertSame('F1', $hotelCopy->rows[0]['room']);

        $this->assertCount(2, $lists->payments($trip)->rows);
        $this->assertSame('missing', $lists->documents($trip)->rows[0]['passport']);

        $pdf = $lists->pdf($busList)->output();
        $this->assertStringStartsWith('%PDF', $pdf);
    }

    public function test_cards_are_issued_once_resolve_by_token_or_serial_and_print_with_a_qr(): void
    {
        [$trip, $family, $single] = $this->trip();
        $cards = app(Cards::class);
        $bp = $family->pilgrims->first();
        app(DocumentWallet::class)->store($bp->pilgrim, Images::photo(), 'photo.jpg', 'image/jpeg', Document::PHOTO);

        $card = $cards->issue($bp, Card::CUSTOMER);
        $this->assertMatchesRegularExpression('/^HJZ-C-\d{6}$/', $card->serial);
        $this->assertSame(40, strlen($card->qr_token));
        $this->assertTrue($cards->issue($bp, Card::CUSTOMER)->is($card));
        $this->assertTrue($cards->resolve($card->qr_token)->is($card));
        $this->assertTrue($cards->resolve(strtolower($card->serial))->is($card));
        $this->assertTrue($cards->resolve($card->url())->is($card));
        $this->assertNull($cards->resolve('nothing'));

        $again = $cards->reissue($bp, Card::CUSTOMER);
        $this->assertFalse($again->is($card));
        $this->assertNull($cards->resolve($card->qr_token), 'a revoked card no longer scans');

        $this->assertStringStartsWith('%PDF', $cards->sheet($family->pilgrims, Card::CUSTOMER)->output());
        $this->assertStringStartsWith('%PDF', $cards->sheet($family->pilgrims, Card::LUGGAGE)->output());
        $this->assertSame(1, $again->fresh()->printed_count);
        $this->assertMatchesRegularExpression('/^HJZ-L-\d{6}$/', $bp->cards()->where('kind', Card::LUGGAGE)->first()->serial);
    }
}
