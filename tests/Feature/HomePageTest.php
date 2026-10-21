<?php

namespace Tests\Feature;

use App\Models\Trip;
use App\Models\User;
use App\Support\Access;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_front_door_is_the_sign_in_page_with_the_trips_ahead(): void
    {
        $this->get('/')->assertRedirect('/portal/login');

        $open = Trip::factory()->open()->create(['name' => 'January 2027 Umrah', 'capacity_total' => 40]);
        $open->priceTiers()->create(['occupancy' => 'quad', 'pax_type' => 'adult', 'amount_minor' => 2850000, 'currency' => 'MVR']);
        $open->hotels()->create(['city' => 'makkah', 'name' => 'Swissotel Al Maqam', 'check_in_on' => $open->departs_on, 'check_out_on' => $open->returns_on]);
        Trip::factory()->create(['name' => 'Secret draft trip']);

        $page = $this->get('/portal/login')->assertOk();
        $page->assertSee('Upcoming trips')
            ->assertSee('January 2027 Umrah')
            ->assertSee('Swissotel Al Maqam')
            ->assertSee('From MVR 28,500')
            ->assertSee('40 seats left')
            ->assertSee('Taking registrations')
            ->assertDontSee('Secret draft trip')
            ->assertSee('Sign in');
    }

    public function test_a_signed_in_person_lands_on_today(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        config(['portal.second_factor_required' => false]);

        $this->actingAs(User::factory()->create()->assignRole(Access::OPERATIONS))->get('/')->assertRedirect('/portal');
    }

    public function test_logo_and_icon_urls_change_when_the_file_changes(): void
    {
        // Cloudflare caches public/ files for hours; a content hash in the URL
        // is what makes a replaced logo show up straight away.
        $hash = substr(md5_file(public_path('images/brand/hijaz-mark.svg')), 0, 10);

        $page = $this->get('/portal/login')->assertOk();

        $page->assertSee("images/brand/hijaz-mark.svg?v={$hash}", false);
        $page->assertSee('images/brand/favicon.svg?v=', false);
    }

    public function test_the_front_door_does_not_mention_rihla(): void
    {
        // Hijaz is its own company; it is only hosted on a rihla.mv subdomain.
        $this->get('/portal/login')->assertDontSee('Rihla', false)->assertDontSee('rihla.mv', false);
    }

    public function test_the_logo_files_exist(): void
    {
        foreach (['hijaz-logo.svg', 'hijaz-logo.png', 'hijaz-logo-on-blue.svg', 'hijaz-logo-on-blue.png', 'hijaz-mark.svg', 'hijaz-mark.png', 'favicon.svg'] as $file) {
            $this->assertFileExists(public_path("images/brand/{$file}"));
        }
    }

    public function test_the_business_contact_details_are_recorded(): void
    {
        $this->assertSame('hijazumra@gmail.com', config('hijaz.email'));
        $this->assertSame(['+9609775123', '+9609906869'], array_column(config('hijaz.phones'), 'tel'));
    }
}
