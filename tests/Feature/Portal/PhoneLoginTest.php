<?php

namespace Tests\Feature\Portal;

use App\Filament\Pages\Auth\Login;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Models\User;
use App\Support\Access;
use App\Support\Phone;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PhoneLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        config(['portal.second_factor_required' => false]);
    }

    public function test_numbers_are_kept_in_one_shape(): void
    {
        $this->assertSame('+9607775123', Phone::normalise('777 5123'));
        $this->assertSame('+9607775123', Phone::normalise('+960 777-5123'));
        $this->assertSame('+9607775123', Phone::normalise('009607775123'));
        $this->assertSame('+447700900123', Phone::normalise('+44 7700 900123'));
        $this->assertNull(Phone::normalise(''));
        $this->assertNull(Phone::normalise('123'));
        $this->assertTrue(Phone::looksLikeOne('777 5123'));
        $this->assertFalse(Phone::looksLikeOne('ali@example.com'));

        $user = User::factory()->create(['phone' => '990 6869']);
        $this->assertSame('+9609906869', $user->fresh()->phone);
    }

    public function test_staff_sign_in_with_a_mobile_number_or_an_email(): void
    {
        $user = User::factory()->create(['email' => 'ali@example.com', 'phone' => '7775123', 'password' => 'a long enough password'])->assignRole(Access::OPERATIONS);

        Livewire::test(Login::class)
            ->fillForm(['email' => '777 5123', 'password' => 'a long enough password'])
            ->call('authenticate')
            ->assertHasNoFormErrors();
        $this->assertAuthenticatedAs($user);

        auth()->logout();
        Livewire::test(Login::class)
            ->fillForm(['email' => 'Ali@Example.com', 'password' => 'a long enough password'])
            ->call('authenticate')
            ->assertHasNoFormErrors();
        $this->assertAuthenticatedAs($user);

        auth()->logout();
        Livewire::test(Login::class)
            ->fillForm(['email' => '777 5124', 'password' => 'a long enough password'])
            ->call('authenticate')
            ->assertHasFormErrors(['email']);
        $this->assertGuest();

        $this->get('/portal/login')->assertOk()->assertSee('Email or mobile number');
    }

    public function test_a_number_belongs_to_one_account(): void
    {
        User::factory()->create(['phone' => '7775123']);
        $this->actingAs(User::factory()->create()->assignRole(Access::ADMIN));

        Livewire::test(CreateUser::class)
            ->fillForm(['name' => 'Second', 'email' => 'second@example.com', 'phone' => '+960 777 5123', 'password' => 'a long enough password', 'roles' => []])
            ->call('create')
            ->assertHasFormErrors(['phone']);
    }

    public function test_the_admin_command_takes_a_number(): void
    {
        $this->artisan('portal:admin', ['email' => 'owner@example.com', 'password' => 'a long enough password', '--phone' => '990 6869'])->assertSuccessful();

        $this->assertSame('+9609906869', User::where('email', 'owner@example.com')->firstOrFail()->phone);
    }
}
