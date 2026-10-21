<?php

namespace Tests\Feature\Pilgrims;

use App\Models\Customer;
use App\Models\Document;
use App\Models\DocumentExtraction;
use App\Models\Island;
use App\Models\Pilgrim;
use App\Models\User;
use App\Services\Documents\DocumentWallet;
use App\Services\Documents\ReaderManager;
use App\Services\Documents\Readers\TesseractReader;
use App\Services\Pilgrims\Acceptor;
use App\Services\Pilgrims\Duplicates;
use App\Services\Pilgrims\IdentityCheck;
use App\Services\Pilgrims\PhotoCheck;
use App\Support\BlindIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Images;
use Tests\TestCase;

class PilgrimTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        config(['documents.reader' => 'manual']);
    }

    public function test_identifiers_are_encrypted_and_found_through_the_blind_index(): void
    {
        $pilgrim = Pilgrim::factory()->create(['id_number' => 'a 123456', 'passport_number' => 'P1234567']);

        $raw = DB::table('pilgrims')->where('id', $pilgrim->id)->first();
        $this->assertStringStartsWith('enc:', $raw->id_number);
        $this->assertSame(BlindIndex::of('A123456'), $raw->id_number_hash);

        $this->assertTrue(Pilgrim::findByIdNumber('A-123456')->is($pilgrim));
        $this->assertTrue(Pilgrim::query()->withPassportNumber('p1234567')->first()->is($pilgrim));
        $this->assertSame('a 123456', $pilgrim->fresh()->id_number);
        $this->assertSame('a•••456', $pilgrim->fresh()->idNumberMasked());
    }

    public function test_a_pilgrim_needs_only_a_name_and_a_customer(): void
    {
        $customer = Customer::factory()->create();
        $pilgrim = $customer->pilgrims()->create(['name_en' => 'AISHATH ALI']);

        $this->assertSame(Pilgrim::REGISTERED, $pilgrim->registration_status);
        $this->assertNull($pilgrim->id_number);
        $this->assertNull($pilgrim->passportOkFor(now()->addMonths(3)));
    }

    public function test_the_passport_must_outlast_the_return_by_the_configured_months(): void
    {
        $pilgrim = Pilgrim::factory()->withPassport('2028-01-10')->create();

        $this->assertTrue($pilgrim->passportOkFor(now()->parse('2027-07-10')));
        $this->assertFalse($pilgrim->passportOkFor(now()->parse('2027-07-11')));
        $this->assertTrue($pilgrim->passportOkFor(now()->parse('2027-12-10'), months: 1));
    }

    public function test_pax_type_follows_age_on_the_departure_day(): void
    {
        $pilgrim = Pilgrim::factory()->create(['date_of_birth' => '2020-07-05']);

        $this->assertSame('child', $pilgrim->paxTypeOn(now()->parse('2027-07-05')));
        $this->assertSame('adult', $pilgrim->paxTypeOn(now()->parse('2032-07-05')));
        $this->assertSame('infant', Pilgrim::factory()->create(['date_of_birth' => '2026-01-01'])->paxTypeOn(now()->parse('2027-07-05')));
    }

    public function test_duplicates_are_found_by_id_passport_or_name_and_birthday(): void
    {
        $existing = Pilgrim::factory()->withPassport()->create(['name_en' => 'AHMED MOHAMED', 'date_of_birth' => '1980-01-01', 'id_number' => 'A111111']);

        $this->assertTrue(Duplicates::find('A111111', null, null, null)->first()->is($existing));
        $this->assertTrue(Duplicates::find(null, $existing->passport_number, null, null)->first()->is($existing));
        $this->assertTrue(Duplicates::find(null, null, 'Ahmed Hassan', '1980-01-01')->first()->is($existing));
        $this->assertTrue(Duplicates::find('A222222', null, 'AHMED', '1990-01-01')->isEmpty());
    }

    public function test_accepting_a_reading_writes_only_that_documents_fields_and_records_them(): void
    {
        $this->actingAs(User::factory()->create());
        $pilgrim = Pilgrim::factory()->create(['name_en' => 'OLD NAME']);
        $wallet = app(DocumentWallet::class);
        $version = $wallet->store($pilgrim, Images::photo(), 'card.jpg', 'image/jpeg', Document::ID_CARD);
        $x = $version->extractions()->create(['reader' => 'test', 'fields' => ['name_en' => 'AHMED MOHAMED', 'id_number' => 'A123456'], 'status' => DocumentExtraction::PROPOSED]);

        app(Acceptor::class)->accept($x, ['name_en' => 'AHMED MOHAMED DIDI', 'id_number' => 'A123456', 'date_of_birth' => '1980-01-01', 'passport_number' => 'SHOULD-NOT-APPLY', 'id_expires_on' => '2030-01-01'], verify: true);

        $pilgrim->refresh();
        $this->assertSame('AHMED MOHAMED DIDI', $pilgrim->name_en);
        $this->assertSame('A123456', $pilgrim->id_number);
        $this->assertNull($pilgrim->passport_number);
        $this->assertSame('2030-01-01', $version->document->fresh()->expires_at->toDateString());
        $this->assertTrue($version->document->fresh()->isVerified());
        $this->assertSame(DocumentExtraction::ACCEPTED, $x->fresh()->status);
        $this->assertSame('AHMED MOHAMED DIDI', $x->fresh()->accepted_fields['name_en']);
    }

    public function test_the_id_card_and_passport_are_compared_and_a_mismatch_can_be_explained(): void
    {
        $this->actingAs(User::factory()->create());
        $pilgrim = Pilgrim::factory()->create();
        $wallet = app(DocumentWallet::class);
        $acceptor = app(Acceptor::class);

        $id = $wallet->store($pilgrim, Images::photo(), 'id.jpg', 'image/jpeg', Document::ID_CARD);
        $acceptor->accept($id->extractions()->create(['reader' => 't', 'status' => 'proposed']), ['name_en' => 'AHMED MOHAMED', 'date_of_birth' => '1980-01-01', 'gender' => 'male']);

        $pp = $wallet->store($pilgrim, Images::photo(460, 600), 'pp.jpg', 'image/jpeg', Document::PASSPORT);
        $acceptor->accept($pp->extractions()->create(['reader' => 't', 'status' => 'proposed']), ['name_en' => 'MOHAMED AHMED', 'date_of_birth' => '1980-01-02', 'gender' => 'male', 'passport_number' => 'P1']);

        $mismatches = $pilgrim->fresh()->unexplainedMismatches();
        $this->assertCount(1, $mismatches);
        $this->assertSame('date_of_birth', $mismatches[0]['field']);

        app(IdentityCheck::class)->explain($pilgrim->fresh(), 'date_of_birth', 'Passport office typo, corrected on the new passport');
        $this->assertSame([], $pilgrim->fresh()->unexplainedMismatches());

        // Re-running keeps the explanation for the same difference.
        app(IdentityCheck::class)->run($pilgrim->fresh());
        $this->assertSame([], $pilgrim->fresh()->unexplainedMismatches());
    }

    public function test_name_similarity_ignores_order_and_punctuation(): void
    {
        $this->assertSame(1.0, IdentityCheck::nameSimilarity('MOHAMED AHMED', 'Ahmed, Mohamed'));
        $this->assertLessThan(0.6, IdentityCheck::nameSimilarity('MOHAMED AHMED', 'HASSAN IBRAHIM'));
    }

    public function test_a_photo_is_checked_for_size_shape_and_background(): void
    {
        $this->assertSame([], PhotoCheck::problems(Images::photo(450, 580), 'image/jpeg'));
        $this->assertStringContainsString('at least', PhotoCheck::problems(Images::photo(100, 130), 'image/jpeg')[0]);
        $this->assertStringContainsString('landscape', implode(' ', PhotoCheck::problems(Images::photo(800, 500), 'image/jpeg')));
        $this->assertStringContainsString('background', implode(' ', PhotoCheck::problems(Images::photo(450, 580, bg: 60), 'image/jpeg')));
        $this->assertStringContainsString('JPEG or PNG', PhotoCheck::problems('%PDF-1.4', 'application/pdf')[0]);
    }

    public function test_the_manual_reader_proposes_nothing_and_records_the_reading(): void
    {
        $pilgrim = Pilgrim::factory()->create();
        $version = app(DocumentWallet::class)->store($pilgrim, Images::photo(), 'id.jpg', 'image/jpeg', Document::ID_CARD);

        $x = app(ReaderManager::class)->read($version);

        $this->assertSame('manual', $x->reader);
        $this->assertNull($x->fields);
        $this->assertSame(DocumentExtraction::PROPOSED, $x->status);
    }

    public function test_tesseract_reads_a_synthetic_passport_and_the_mrz_checks_out(): void
    {
        if (! (new TesseractReader)->isAvailable()) {
            $this->markTestSkipped('tesseract is not installed here');
        }

        config(['documents.reader' => 'tesseract']);
        $pilgrim = Pilgrim::factory()->create();
        $image = Images::textCard([
            'PASSPORT',
            'Surname: ERIKSSON',
            'Given names: ANNA MARIA',
            'Passport No: L898902C3',
            'Date of birth: 12 AUG 1974',
            '',
            '',
            '',
            'P<UTOERIKSSON<<ANNA<MARIA<<<<<<<<<<<<<<<<<<<',
            'L898902C36UTO7408122F1204159ZE184226B<<<<<10',
        ], 1800, 1100, 34);
        $version = app(DocumentWallet::class)->store($pilgrim, $image, 'pp.png', 'image/png', Document::PASSPORT);

        $x = app(ReaderManager::class)->read($version);

        $this->assertSame('tesseract', $x->reader);
        $this->assertNull($x->error);
        $this->assertSame('L898902C3', $x->fields['passport_number'] ?? null, $x->raw_text);
        $this->assertTrue($x->mrz_valid, $x->raw_text);
        $this->assertSame('1974-08-12', $x->fields['date_of_birth']);
    }

    public function test_tesseract_reads_a_synthetic_id_card(): void
    {
        if (! (new TesseractReader)->isAvailable()) {
            $this->markTestSkipped('tesseract is not installed here');
        }

        config(['documents.reader' => 'tesseract']);
        Island::factory()->create(['name' => 'Maafushi', 'atoll_code' => 'K', 'atoll_name' => 'Kaafu']);
        $pilgrim = Pilgrim::factory()->create();
        $image = Images::textCard([
            'REPUBLIC OF MALDIVES',
            'IDENTITY CARD',
            'NAME',
            'AHMED MOHAMED DIDI',
            'ID NUMBER A123456',
            'SEX M      DATE OF BIRTH 12/08/1974',
            'ADDRESS',
            'BLUE HOUSE, K. MAAFUSHI',
            'EXPIRY 01/01/2030',
        ], 1800, 1100, 40);
        $version = app(DocumentWallet::class)->store($pilgrim, $image, 'id.png', 'image/png', Document::ID_CARD);

        $x = app(ReaderManager::class)->read($version);

        $this->assertSame('A123456', $x->fields['id_number'] ?? null, $x->raw_text);
        $this->assertSame('AHMED MOHAMED DIDI', $x->fields['name_en'] ?? null, $x->raw_text);
        $this->assertSame('1974-08-12', $x->fields['date_of_birth'] ?? null, $x->raw_text);
        $this->assertSame((string) Island::first()->id, $x->fields['island_id'] ?? null, $x->raw_text);
    }
}
