<?php

namespace Tests\Feature\Portal;

use App\Exceptions\LastAdmin;
use App\Models\User;
use App\Support\Access;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AccessMatrixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_every_role_has_a_row_in_the_matrix_and_every_verb_exists(): void
    {
        $matrix = Access::matrix();

        $this->assertEqualsCanonicalizing(Access::ROLES, array_keys($matrix));

        foreach ($matrix as $role => $permissions) {
            foreach ($permissions as $permission) {
                $this->assertContains($permission, Access::PERMISSIONS, "[{$role}] names [{$permission}], which is not a permission.");
            }
        }
    }

    public function test_admin_holds_no_permissions_and_can_do_everything(): void
    {
        $this->assertSame([], Access::matrix()[Access::ADMIN]);

        $admin = User::factory()->create()->assignRole(Access::ADMIN);

        foreach (Access::PERMISSIONS as $permission) {
            $this->assertTrue($admin->can($permission));
        }

        $this->assertTrue($admin->can('anything.later-phases-add'));
    }

    public function test_sets_are_built_by_inclusion(): void
    {
        $this->assertFalse(User::factory()->create()->assignRole(Access::OPERATIONS)->can('user.viewAny'));
        $this->assertFalse(User::factory()->create()->assignRole(Access::FINANCE)->can('audit.viewAny'));
        $this->assertFalse(User::factory()->create()->assignRole(Access::REGISTRATION)->can('rate.viewAny'));
        $this->assertTrue(User::factory()->create()->assignRole(Access::FINANCE)->can('rate.update'));
        $this->assertFalse(User::factory()->create()->assignRole(Access::READ_ONLY)->can('rate.update'));
    }

    public function test_the_seeder_revokes_what_the_matrix_no_longer_grants(): void
    {
        Permission::findOrCreate('stale.verb', 'web');
        Role::findByName(Access::READ_ONLY)->givePermissionTo('stale.verb');

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertFalse(User::factory()->create()->assignRole(Access::READ_ONLY)->can('stale.verb'));
    }

    public function test_the_last_admin_cannot_be_deleted(): void
    {
        $only = User::factory()->create()->assignRole(Access::ADMIN);

        $this->expectException(LastAdmin::class);
        $only->delete();
    }

    public function test_an_admin_can_go_once_another_exists(): void
    {
        $first = User::factory()->create()->assignRole(Access::ADMIN);
        User::factory()->create()->assignRole(Access::ADMIN);

        $this->assertTrue($first->delete());
    }

    public function test_the_admin_command_creates_or_promotes(): void
    {
        $this->artisan('portal:admin', ['email' => 'Owner@Example.com', 'password' => 'a long enough password'])
            ->assertSuccessful();

        $owner = User::where('email', 'owner@example.com')->firstOrFail();
        $this->assertTrue($owner->hasRole(Access::ADMIN));
        $this->assertTrue($owner->is_active);

        $existing = User::factory()->create(['email' => 'staff@example.com'])->assignRole(Access::OPERATIONS);
        $this->artisan('portal:admin', ['email' => 'staff@example.com', 'password' => 'another long password'])
            ->assertSuccessful();
        $this->assertTrue($existing->fresh()->hasRole(Access::ADMIN));
        $this->assertSame(2, User::count());
    }

    public function test_the_admin_command_refuses_a_short_password(): void
    {
        $this->artisan('portal:admin', ['email' => 'owner@example.com', 'password' => 'short'])->assertFailed();
        $this->assertSame(0, User::count());
    }
}
