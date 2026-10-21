<?php

namespace Tests\Feature\Portal;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\User;
use App\Policies\AuditLogPolicy;
use App\Support\Access;
use App\Support\AuditContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_a_change_is_recorded_with_who_made_it(): void
    {
        $finance = User::factory()->create(['name' => 'Aminath'])->assignRole(Access::FINANCE);
        $this->actingAs($finance);

        $account = Account::factory()->create(['name' => 'Bank USD']);
        $account->update(['name' => 'Bank USD (BML)']);

        $created = AuditLog::where('event', AuditLog::CREATED)->where('auditable_type', Account::class)->firstOrFail();
        $this->assertSame('Aminath', $created->user_name);
        $this->assertSame('Bank USD', $created->new_values['name']);

        $updated = AuditLog::where('event', AuditLog::UPDATED)->firstOrFail();
        $this->assertSame(['name' => 'Bank USD'], $updated->old_values);
        $this->assertSame(['name' => 'Bank USD (BML)'], $updated->new_values);
    }

    public function test_an_account_number_is_recorded_as_changed_but_never_as_a_value(): void
    {
        Account::factory()->create(['account_number' => '7730000123456']);
        $account = Account::first();
        $account->update(['account_number' => '7730000654321']);

        foreach (AuditLog::all() as $entry) {
            $values = json_encode([$entry->old_values, $entry->new_values]);
            $this->assertStringNotContainsString('7730000', $values);
        }

        $this->assertSame('[redacted]', AuditLog::where('event', AuditLog::UPDATED)->firstOrFail()->new_values['account_number']);
    }

    public function test_passwords_and_second_factor_secrets_never_reach_the_trail(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['password' => 'a brand new password 123'])->save();
        $user->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');

        foreach (AuditLog::all() as $entry) {
            $this->assertArrayNotHasKey('password', (array) $entry->new_values);
            $this->assertArrayNotHasKey('app_authentication_secret', (array) $entry->new_values);
        }
    }

    public function test_the_channel_says_how_a_change_arrived(): void
    {
        Account::factory()->create();
        $this->assertSame(AuditContext::CONSOLE, AuditLog::latest('id')->firstOrFail()->channel);

        AuditContext::use(AuditContext::TELEGRAM);
        Account::factory()->create(['name' => 'Another']);
        AuditContext::reset();
        $this->assertSame(AuditContext::TELEGRAM, AuditLog::latest('id')->firstOrFail()->channel);
    }

    public function test_an_entry_cannot_be_edited_or_deleted_from_a_screen(): void
    {
        Account::factory()->create();
        $entry = AuditLog::firstOrFail();
        $admin = User::factory()->create()->assignRole(Access::ADMIN);

        $this->assertTrue($admin->can('view', $entry));
        $this->assertFalse(app(AuditLogPolicy::class)->update($admin));
        $this->assertFalse(app(AuditLogPolicy::class)->delete($admin));
    }

    public function test_the_trail_outlives_the_account_that_wrote_it(): void
    {
        $admin = User::factory()->create(['name' => 'Gone'])->assignRole(Access::ADMIN);
        User::factory()->create()->assignRole(Access::ADMIN);
        $this->actingAs($admin);

        Account::factory()->create();
        $admin->delete();

        $entry = AuditLog::where('auditable_type', Account::class)->firstOrFail();
        $this->assertNull($entry->user_id);
        $this->assertSame('Gone', $entry->actor);
    }
}
