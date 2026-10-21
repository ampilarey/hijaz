<?php

namespace Tests\Feature\Pilgrims;

use App\Models\Document;
use App\Models\DocumentBatch;
use App\Models\DocumentBatchItem;
use App\Models\Pilgrim;
use App\Models\Trip;
use App\Models\User;
use App\Services\Pilgrims\BulkVisas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Images;
use Tests\TestCase;

class BulkVisasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        config(['documents.reader' => 'manual']);
        $this->actingAs(User::factory()->create());
    }

    public function test_visas_in_one_pdf_are_split_matched_by_passport_number_and_attached_on_confirm(): void
    {
        $trip = Trip::factory()->create();
        $ahmed = Pilgrim::factory()->create(['name_en' => 'AHMED MOHAMED', 'passport_number' => 'P1111111']);
        $aisha = Pilgrim::factory()->create(['name_en' => 'AISHATH ALI', 'passport_number' => 'P2222222']);
        Pilgrim::factory()->create(['name_en' => 'SOMEBODY ELSE', 'passport_number' => 'P3333333']);

        $pdf = Images::multiPagePdf([
            ['KINGDOM OF SAUDI ARABIA', 'Visa No: 100001', 'Full Name: AHMED MOHAMED', 'Passport No: P1111111', 'Date of Expiry: 15/09/2027'],
            ['KINGDOM OF SAUDI ARABIA', 'Visa No: 100002', 'Full Name: AISHATH ALI', 'Passport No: P2222222', 'Date of Expiry: 15/09/2027'],
            ['KINGDOM OF SAUDI ARABIA', 'Visa No: 100003', 'Full Name: UNKNOWN PERSON', 'Passport No: P9999999', 'Date of Expiry: 15/09/2027'],
        ]);

        $batch = app(BulkVisas::class)->open($trip, [['bytes' => $pdf, 'name' => 'visas.pdf', 'mime' => 'application/pdf']]);

        $this->assertSame(3, $batch->file_count);
        $this->assertSame(2, $batch->matched_count);
        $this->assertSame('passport_number', $batch->items->first()->match_reason);
        $this->assertTrue($batch->items->first()->pilgrim->is($ahmed));
        $this->assertSame(DocumentBatchItem::UNMATCHED, $batch->items->last()->status);

        $attached = app(BulkVisas::class)->confirm($batch);

        $this->assertSame(2, $attached);
        $this->assertSame(DocumentBatch::CONFIRMED, $batch->fresh()->status);
        $visa = $ahmed->fresh()->document(Document::VISA, $trip->id);
        $this->assertNotNull($visa);
        $this->assertTrue($visa->isVerified());
        $this->assertSame('100001', $visa->number);
        $this->assertSame('2027-09-15', $visa->expires_at->toDateString());
        $this->assertNotNull($aisha->fresh()->document(Document::VISA, $trip->id));
        $this->assertStringContainsString('%PDF', $visa->currentVersion()->contents());
    }

    public function test_an_unmatched_file_can_be_assigned_by_hand_or_discarded(): void
    {
        $trip = Trip::factory()->create();
        $pilgrim = Pilgrim::factory()->create(['name_en' => 'HASSAN IBRAHIM']);
        $pdf = Images::pdfWithText(['Visa No: 5', 'Passport No: P0000000']);

        $batch = app(BulkVisas::class)->open($trip, [['bytes' => $pdf, 'name' => 'one.pdf', 'mime' => 'application/pdf']]);
        $item = $batch->items->first();
        $this->assertSame(DocumentBatchItem::UNMATCHED, $item->status);

        app(BulkVisas::class)->assign($item, $pilgrim);
        $this->assertSame(DocumentBatchItem::ASSIGNED, $item->fresh()->status);

        app(BulkVisas::class)->confirm($batch, verify: false);
        $this->assertSame(Document::PENDING, $pilgrim->fresh()->document(Document::VISA, $trip->id)->status);
    }

    public function test_a_close_name_match_is_offered_but_flagged(): void
    {
        $trip = Trip::factory()->create();
        $pilgrim = Pilgrim::factory()->create(['name_en' => 'FATHIMATH SHAREEF', 'passport_number' => null]);
        $pdf = Images::pdfWithText(['Full Name: FATHIMATH SHAREEF', 'Passport No: P5555555']);

        $batch = app(BulkVisas::class)->open($trip, [['bytes' => $pdf, 'name' => 'f.pdf', 'mime' => 'application/pdf']]);

        $this->assertSame('name', $batch->items->first()->match_reason);
        $this->assertTrue($batch->items->first()->pilgrim->is($pilgrim));
    }
}
