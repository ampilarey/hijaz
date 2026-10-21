<?php

namespace Tests\Feature\Documents;

use App\Models\AuditLog;
use App\Models\Document;
use App\Models\Pilgrim;
use App\Models\Trip;
use App\Models\User;
use App\Services\Documents\DocumentWallet;
use App\Support\Access;
use App\Support\EncryptedFile;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Images;
use Tests\TestCase;

class DocumentWalletTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        $this->seed(RolesAndPermissionsSeeder::class);
        config(['portal.second_factor_required' => false]);
    }

    public function test_a_copy_is_stored_encrypted_versioned_and_deduplicated(): void
    {
        $pilgrim = Pilgrim::factory()->create();
        $wallet = app(DocumentWallet::class);
        $bytes = Images::photo();

        $v1 = $wallet->store($pilgrim, $bytes, 'card.jpg', 'image/jpeg', Document::ID_CARD);
        $again = $wallet->store($pilgrim, $bytes, 'card.jpg', 'image/jpeg', Document::ID_CARD);
        $v2 = $wallet->store($pilgrim, Images::photo(460, 600), 'card2.jpg', 'image/jpeg', Document::ID_CARD);

        $this->assertTrue($again->is($v1));
        $this->assertSame(2, $v2->version);
        $this->assertNotNull($v1->fresh()->superseded_at);
        $this->assertSame(1, $pilgrim->documents()->count());

        $raw = Storage::disk('documents')->get($v2->path);
        $this->assertStringStartsWith(EncryptedFile::MAGIC, $raw);
        $this->assertStringNotContainsString('JFIF', $raw);
        $this->assertSame(Images::photo(460, 600), $v2->contents());
    }

    public function test_a_new_version_sends_a_verified_document_back_to_pending(): void
    {
        $pilgrim = Pilgrim::factory()->create();
        $wallet = app(DocumentWallet::class);
        $v1 = $wallet->store($pilgrim, Images::photo(), 'p.jpg', 'image/jpeg', Document::PASSPORT);
        $wallet->verify($v1->document, User::factory()->create()->id);
        $this->assertTrue($v1->document->fresh()->isVerified());

        $wallet->store($pilgrim, Images::photo(470, 600), 'p2.jpg', 'image/jpeg', Document::PASSPORT);

        $this->assertSame(Document::PENDING, $v1->document->fresh()->status);
    }

    public function test_a_visa_is_one_document_per_trip(): void
    {
        $pilgrim = Pilgrim::factory()->create();
        $trip1 = Trip::factory()->create();
        $trip2 = Trip::factory()->create(['departs_on' => now()->addMonths(6), 'returns_on' => now()->addMonths(6)->addDays(14)]);
        $wallet = app(DocumentWallet::class);

        $wallet->store($pilgrim, Images::photo(), 'v1.jpg', 'image/jpeg', Document::VISA, $trip1->id, ['number' => 'V1']);
        $wallet->store($pilgrim, Images::photo(460, 600), 'v2.jpg', 'image/jpeg', Document::VISA, $trip2->id, ['number' => 'V2']);

        $this->assertSame(2, $pilgrim->documents()->where('type', Document::VISA)->count());
        $this->assertSame('V1', $pilgrim->fresh()->document(Document::VISA, $trip1->id)->number);
    }

    public function test_downloads_need_a_signed_link_a_permission_and_leave_an_audit_row(): void
    {
        $pilgrim = Pilgrim::factory()->create();
        $wallet = app(DocumentWallet::class);
        $version = $wallet->store($pilgrim, Images::photo(), 'card.jpg', 'image/jpeg', Document::ID_CARD);

        $registration = User::factory()->create()->assignRole(Access::REGISTRATION);
        $leader = User::factory()->create()->assignRole(Access::GROUP_LEADER);

        $this->actingAs($registration)->get('/documents/'.$version->id.'/download')->assertForbidden(); // unsigned
        $this->actingAs($leader)->get($wallet->downloadUrl($version))->assertForbidden();           // no permission

        $response = $this->actingAs($registration)->get($wallet->downloadUrl($version));
        $response->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->assertSame(Images::photo(), $response->streamedContent());

        $this->assertTrue(AuditLog::where('event', AuditLog::DOWNLOADED)->where('auditable_id', $version->id)->exists());

        $this->actingAs($registration)->get($wallet->previewUrl($version))->assertOk()->assertHeader('Content-Disposition', 'inline; filename="card.jpg"');
        $this->assertTrue(AuditLog::where('event', 'viewed')->exists());
    }

    public function test_too_many_uploads_of_one_type_in_a_day_are_refused(): void
    {
        $pilgrim = Pilgrim::factory()->create();
        $wallet = app(DocumentWallet::class);

        for ($i = 0; $i < DocumentWallet::MAX_VERSIONS_A_DAY; $i++) {
            $wallet->store($pilgrim, Images::photo(400 + $i, 520), "p{$i}.jpg", 'image/jpeg', Document::PHOTO);
        }

        $this->assertTrue($wallet->tooManyToday($pilgrim, Document::PHOTO));
        $this->assertFalse($wallet->tooManyToday($pilgrim, Document::PASSPORT));
    }
}
