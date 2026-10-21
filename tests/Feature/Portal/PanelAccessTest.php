<?php

namespace Tests\Feature\Portal;

use App\Filament\Resources\AuditLogs\AuditLogResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use App\Support\Access;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PanelAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        config(['portal.second_factor_required' => false]);
    }

    private function staff(string $role, array $attributes = []): User
    {
        return User::factory()->create($attributes)->assignRole($role);
    }

    public function test_the_login_page_renders(): void
    {
        $this->get('/portal/login')->assertOk()->assertSee('Hijaz Portal');
    }

    public function test_a_guest_is_sent_to_login(): void
    {
        $this->get('/portal')->assertRedirect('/portal/login');
    }

    public function test_every_role_can_open_the_portal(): void
    {
        foreach (Access::ROLES as $role) {
            $this->actingAs($this->staff($role))->get('/portal')->assertOk();
        }
    }

    public function test_an_account_without_a_role_gets_nowhere(): void
    {
        $this->actingAs(User::factory()->create())->get('/portal')->assertForbidden();
    }

    public function test_a_deactivated_account_gets_nowhere_even_with_a_role(): void
    {
        $this->actingAs($this->staff(Access::ADMIN, ['is_active' => false]))->get('/portal')->assertForbidden();
    }

    public function test_only_admin_manages_staff_and_reads_the_audit_trail(): void
    {
        $this->actingAs($this->staff(Access::ADMIN))->get(UserResource::getUrl())->assertOk();
        $this->actingAs($this->staff(Access::ADMIN))->get(AuditLogResource::getUrl())->assertOk();

        foreach ([Access::OPERATIONS, Access::FINANCE, Access::REGISTRATION, Access::GROUP_LEADER, Access::READ_ONLY] as $role) {
            $this->actingAs($this->staff($role))->get(UserResource::getUrl())->assertForbidden();
            $this->actingAs($this->staff($role))->get(AuditLogResource::getUrl())->assertForbidden();
        }
    }

    public function test_admin_and_finance_must_set_up_an_authenticator_app(): void
    {
        config(['portal.second_factor_required' => true]);

        $this->actingAs($this->staff(Access::ADMIN))->get('/portal')
            ->assertRedirect('/portal/multi-factor-authentication/set-up');
        $this->actingAs($this->staff(Access::FINANCE))->get('/portal')
            ->assertRedirect('/portal/multi-factor-authentication/set-up');

        $this->actingAs($this->staff(Access::OPERATIONS))->get('/portal')->assertOk();
    }

    public function test_an_admin_with_an_app_set_up_is_not_redirected(): void
    {
        config(['portal.second_factor_required' => true]);

        $admin = $this->staff(Access::ADMIN);
        $admin->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');

        $this->actingAs($admin)->get('/portal')->assertOk();
    }

    public function test_the_bottom_bar_shows_what_the_person_may_open(): void
    {
        $finance = $this->actingAs($this->staff(Access::FINANCE))->get('/portal')->assertOk();
        $finance->assertSee('hz-bottom-nav', false)->assertSee('Bookings')->assertSee('Payments');

        $leader = $this->actingAs($this->staff(Access::GROUP_LEADER))->get('/portal')->assertOk();
        $leader->assertSee('hz-bottom-nav', false)->assertSee('Bookings')->assertDontSee('Rates');
    }

    public function test_the_panel_links_the_brand_mark_and_favicon(): void
    {
        $this->actingAs($this->staff(Access::OPERATIONS))->get('/portal')
            ->assertOk()
            ->assertSee('images/brand/hijaz-mark.svg?v=', false)
            ->assertSee('images/brand/favicon.svg?v=', false);
    }
}
