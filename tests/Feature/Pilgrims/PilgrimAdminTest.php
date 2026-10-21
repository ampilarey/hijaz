<?php

namespace Tests\Feature\Pilgrims;

use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\DocumentBatches\DocumentBatchResource;
use App\Filament\Resources\Documents\DocumentResource;
use App\Filament\Resources\Pilgrims\Pages\CreatePilgrim;
use App\Filament\Resources\Pilgrims\Pages\EditPilgrim;
use App\Filament\Resources\Pilgrims\Pages\ListPilgrims;
use App\Filament\Resources\Pilgrims\PilgrimResource;
use App\Filament\Resources\Pilgrims\RelationManagers\DocumentsRelationManager;
use App\Models\Customer;
use App\Models\Document;
use App\Models\DocumentExtraction;
use App\Models\Pilgrim;
use App\Models\User;
use App\Services\Documents\DocumentWallet;
use App\Services\Telegram\Bot;
use App\Services\Telegram\Linker;
use App\Support\Access;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\Images;
use Tests\TestCase;

class PilgrimAdminTest extends TestCase
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

    public function test_who_may_open_what(): void
    {
        $pilgrim = Pilgrim::factory()->create();

        foreach ([Access::ADMIN, Access::OPERATIONS, Access::REGISTRATION, Access::FINANCE, Access::READ_ONLY, Access::GROUP_LEADER] as $role) {
            $this->actingAs($this->staff($role))->get(PilgrimResource::getUrl())->assertOk();
        }

        $this->actingAs($this->staff(Access::REGISTRATION))->get(PilgrimResource::getUrl('create'))->assertOk();
        $this->actingAs($this->staff(Access::REGISTRATION))->get(PilgrimResource::getUrl('edit', ['record' => $pilgrim]))->assertOk();
        $this->actingAs($this->staff(Access::REGISTRATION))->get(CustomerResource::getUrl())->assertOk();
        $this->actingAs($this->staff(Access::REGISTRATION))->get(DocumentResource::getUrl())->assertOk();
        $this->actingAs($this->staff(Access::REGISTRATION))->get(DocumentBatchResource::getUrl())->assertOk();

        $this->actingAs($this->staff(Access::FINANCE))->get(PilgrimResource::getUrl('create'))->assertForbidden();
        $this->actingAs($this->staff(Access::GROUP_LEADER))->get(CustomerResource::getUrl())->assertForbidden();
        $this->actingAs($this->staff(Access::GROUP_LEADER))->get(DocumentBatchResource::getUrl())->assertForbidden();
    }

    public function test_registration_creates_a_pilgrim_with_a_name_a_phone_and_a_new_customer(): void
    {
        $this->actingAs($this->staff(Access::REGISTRATION));
        $customer = Customer::factory()->create(['name' => 'Ali']);

        Livewire::test(CreatePilgrim::class)
            ->fillForm(['name_en' => 'ali ahmed', 'phone' => '7771234', 'customer_id' => $customer->id, 'relationship_to_head' => 'self'])
            ->call('create')
            ->assertHasNoFormErrors();

        $pilgrim = Pilgrim::firstOrFail();
        $this->assertSame('ALI AHMED', $pilgrim->name_en);
        $this->assertSame(Pilgrim::REGISTERED, $pilgrim->registration_status);
        $this->assertTrue($customer->fresh()->head->is($pilgrim));
    }

    public function test_a_duplicate_id_number_is_refused_on_the_form(): void
    {
        $this->actingAs($this->staff(Access::REGISTRATION));
        Pilgrim::factory()->create(['id_number' => 'A123456', 'name_en' => 'FIRST']);
        $customer = Customer::factory()->create();

        Livewire::test(CreatePilgrim::class)
            ->fillForm(['name_en' => 'SECOND', 'customer_id' => $customer->id, 'id_number' => 'a123456'])
            ->call('create')
            ->assertHasFormErrors(['id_number']);
    }

    public function test_a_scanned_id_card_is_saved_with_the_record_and_its_reading_accepted(): void
    {
        $this->actingAs($this->staff(Access::REGISTRATION));
        $customer = Customer::factory()->create();
        $file = UploadedFile::fake()->createWithContent('card.jpg', Images::photo());

        Livewire::test(CreatePilgrim::class)
            ->fillForm(['id_card_scan' => $file, 'name_en' => 'AHMED MOHAMED', 'id_number' => 'A654321', 'customer_id' => $customer->id, 'date_of_birth' => '1980-01-01'])
            ->call('create')
            ->assertHasNoFormErrors();

        $pilgrim = Pilgrim::firstOrFail();
        $doc = $pilgrim->document(Document::ID_CARD);
        $this->assertNotNull($doc);
        $this->assertSame(1, $doc->versions()->count());
        $x = $doc->latestExtraction();
        $this->assertSame(DocumentExtraction::ACCEPTED, $x->status);
        $this->assertSame('A654321', $x->accepted_fields['id_number']);
    }

    public function test_a_copy_is_uploaded_from_the_pilgrim_page_and_then_accepted_and_verified(): void
    {
        $registration = $this->staff(Access::REGISTRATION);
        $operations = $this->staff(Access::OPERATIONS);
        $pilgrim = Pilgrim::factory()->create();

        $this->actingAs($registration);
        Livewire::test(DocumentsRelationManager::class, ['ownerRecord' => $pilgrim, 'pageClass' => EditPilgrim::class])
            ->callTableAction('upload', data: ['type' => Document::PASSPORT, 'file' => UploadedFile::fake()->createWithContent('pp.jpg', Images::photo())])
            ->assertHasNoTableActionErrors();

        $doc = $pilgrim->fresh()->document(Document::PASSPORT);
        $this->assertNotNull($doc);
        $this->assertTrue($doc->latestExtraction()->isProposed());

        // Registration accepts the (empty) reading with the values typed in; verifying is not theirs.
        Livewire::test(DocumentsRelationManager::class, ['ownerRecord' => $pilgrim->fresh(), 'pageClass' => EditPilgrim::class])
            ->callTableAction('accept', $doc, data: ['name_en' => 'AHMED MOHAMED', 'passport_number' => 'P7654321', 'passport_expires_on' => '2032-01-01', 'verify' => true])
            ->assertHasNoTableActionErrors();

        $this->assertSame('P7654321', $pilgrim->fresh()->passport_number);
        $this->assertSame(Document::PENDING, $doc->fresh()->status);

        $this->actingAs($operations);
        Livewire::test(DocumentsRelationManager::class, ['ownerRecord' => $pilgrim->fresh(), 'pageClass' => EditPilgrim::class])
            ->callTableAction('verify', $doc->fresh());

        $this->assertTrue($doc->fresh()->isVerified());
    }

    public function test_only_operations_and_admin_verify_a_registration(): void
    {
        $pilgrim = Pilgrim::factory()->create();

        $this->actingAs($this->staff(Access::REGISTRATION));
        Livewire::test(EditPilgrim::class, ['record' => $pilgrim->getKey()])->assertActionHidden('verify_pilgrim');

        $this->actingAs($this->staff(Access::OPERATIONS));
        Livewire::test(EditPilgrim::class, ['record' => $pilgrim->getKey()])->callAction('verify_pilgrim');

        $this->assertTrue($pilgrim->fresh()->isVerified());
    }

    public function test_the_list_finds_a_pilgrim_by_id_number_without_scanning_ciphertext(): void
    {
        $this->actingAs($this->staff(Access::OPERATIONS));
        Pilgrim::factory()->create(['id_number' => 'A123456', 'name_en' => 'TARGET PERSON']);
        Pilgrim::factory()->create(['id_number' => 'A999999', 'name_en' => 'OTHER PERSON']);

        Livewire::test(ListPilgrims::class)
            ->searchTable('A123456')
            ->assertCanSeeTableRecords(Pilgrim::where('name_en', 'TARGET PERSON')->get())
            ->assertCanNotSeeTableRecords(Pilgrim::where('name_en', 'OTHER PERSON')->get());
    }

    public function test_the_queue_shows_what_is_waiting_and_the_bot_finds_people(): void
    {
        $operations = $this->staff(Access::OPERATIONS);
        $pilgrim = Pilgrim::factory()->create(['name_en' => 'MARIYAM NAZEEHA', 'id_number' => 'A555555']);
        $version = app(DocumentWallet::class)->store($pilgrim, Images::photo(), 'id.jpg', 'image/jpeg', Document::ID_CARD);
        $version->extractions()->create(['reader' => 't', 'fields' => ['name_en' => 'X'], 'status' => DocumentExtraction::PROPOSED]);

        $this->actingAs($operations)->get(DocumentResource::getUrl())->assertOk()->assertSee('MARIYAM NAZEEHA');
        $this->actingAs($operations)->get('/portal')->assertOk()->assertSee('<span class="hz-row__count">1</span>', false)->assertSee('reading to accept');

        config(['telegram.bot_token' => '1:T', 'telegram.bot_username' => 'b']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        app(Linker::class)->redeem(app(Linker::class)->issueCode($operations), 5);
        $this->postJson('/api/telegram/webhook', ['update_id' => 1, 'message' => ['message_id' => 1, 'chat' => ['id' => 5], 'from' => ['id' => 5], 'text' => '/find A555555']], ['X-Telegram-Bot-Api-Secret-Token' => Bot::webhookSecret()])->assertOk();

        Http::assertSent(fn ($r): bool => str_ends_with($r->url(), '/sendMessage') && str_contains($r['text'], 'MARIYAM NAZEEHA') && str_contains($r['text'], 'ID card: pending'));
    }

    public function test_a_photo_sent_to_the_bot_with_an_id_number_caption_is_stored(): void
    {
        $registration = $this->staff(Access::REGISTRATION);
        $pilgrim = Pilgrim::factory()->create(['id_number' => 'A777777']);
        config(['telegram.bot_token' => '1:T', 'telegram.bot_username' => 'b']);
        Http::fake([
            'api.telegram.org/bot1:T/getFile' => Http::response(['ok' => true, 'result' => ['file_path' => 'photos/file_1.jpg']]),
            'api.telegram.org/file/bot1:T/photos/file_1.jpg' => Http::response(Images::photo(), 200, ['Content-Type' => 'image/jpeg']),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []]),
        ]);
        app(Linker::class)->redeem(app(Linker::class)->issueCode($registration), 9);

        $this->postJson('/api/telegram/webhook', ['update_id' => 2, 'message' => ['message_id' => 2, 'chat' => ['id' => 9], 'from' => ['id' => 9], 'caption' => 'A777777 passport', 'photo' => [['file_id' => 'small', 'file_unique_id' => 'u1'], ['file_id' => 'big', 'file_unique_id' => 'u2']]]], ['X-Telegram-Bot-Api-Secret-Token' => Bot::webhookSecret()])->assertOk();

        $doc = $pilgrim->fresh()->document(Document::PASSPORT);
        $this->assertNotNull($doc);
        $this->assertSame('telegram', $doc->currentVersion()->channel);
        Http::assertSent(fn ($r): bool => str_ends_with($r->url(), '/sendMessage') && str_contains($r['text'], 'Passport saved'));
    }

    public function test_the_import_command_creates_families_and_skips_known_people(): void
    {
        Pilgrim::factory()->create(['id_number' => 'A100000', 'name_en' => 'KNOWN PERSON']);
        $csv = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($csv, "name,id_number,date_of_birth,gender,phone,family,passport_number\nAhmed Ali,A200000,01/02/1970,M,7770001,Ali family,P2000000\nAishath Ali,A200001,05/06/1972,F,7770001,Ali family,\nKnown Person,A100000,,,,,\nSolo Traveller,A300000,1990-01-01,male,7770002,,\n");

        $this->artisan('pilgrims:import', ['file' => $csv, '--dry-run' => true])->expectsOutputToContain('3 would be created, 1 skipped')->assertSuccessful();
        $this->assertSame(1, Pilgrim::count());

        $this->artisan('pilgrims:import', ['file' => $csv])->assertSuccessful();

        $this->assertSame(4, Pilgrim::count());
        $family = Customer::where('name', 'Ali family')->firstOrFail();
        $this->assertSame(Customer::FAMILY, $family->type);
        $this->assertSame(2, $family->pilgrims()->count());
        $this->assertSame('AHMED ALI', $family->head->name_en);
        $this->assertSame('1970-02-01', $family->head->date_of_birth->toDateString());
        $this->assertSame(Customer::INDIVIDUAL, Pilgrim::findByIdNumber('A300000')->customer->type);
    }
}
