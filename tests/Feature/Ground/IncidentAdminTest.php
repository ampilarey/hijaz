<?php

namespace Tests\Feature\Ground;

use App\Filament\Resources\Incidents\IncidentResource;
use App\Filament\Resources\Incidents\Pages\ListIncidents;
use App\Filament\Resources\Trips\Pages\EditTrip;
use App\Filament\Resources\Trips\RelationManagers\RollCallsRelationManager;
use App\Models\Incident;
use App\Models\User;
use App\Support\Access;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\PricedTrip;
use Tests\TestCase;

class IncidentAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        config(['portal.second_factor_required' => false]);
    }

    private function staff(string $role): User
    {
        return User::factory()->create()->assignRole($role);
    }

    public function test_the_office_logs_and_resolves_incidents_and_starts_counts(): void
    {
        $trip = PricedTrip::make();
        $trip->forceFill(['status' => 'travelling'])->save();

        foreach ([Access::OPERATIONS, Access::FINANCE, Access::READ_ONLY, Access::GROUP_LEADER] as $role) {
            $this->actingAs($this->staff($role))->get(IncidentResource::getUrl())->assertOk();
        }
        $this->actingAs($this->staff(Access::REGISTRATION))->get(IncidentResource::getUrl())->assertForbidden();

        $this->actingAs($this->staff(Access::OPERATIONS));
        Livewire::test(ListIncidents::class)
            ->callTableAction('raise', data: ['trip_id' => $trip->id, 'severity' => 'minor', 'category' => 'transport', 'summary' => 'Bus late', 'happened_at' => now()->toDateTimeString()])
            ->assertHasNoTableActionErrors();
        $incident = Incident::firstOrFail();
        $this->assertTrue($incident->isOpen());

        Livewire::test(ListIncidents::class)
            ->callTableAction('resolve', $incident, data: ['resolution' => 'Another bus came'])
            ->assertHasNoTableActionErrors();
        $this->assertFalse($incident->fresh()->isOpen());
        $this->assertSame('Another bus came', $incident->fresh()->resolution);

        Livewire::test(RollCallsRelationManager::class, ['ownerRecord' => $trip->fresh(), 'pageClass' => EditTrip::class])
            ->callTableAction('start', data: ['moment' => 'At the airport'])
            ->assertHasNoTableActionErrors();
        $this->assertSame(1, $trip->rollCalls()->count());

        $open = Incident::query()->create(['trip_id' => $trip->id, 'severity' => 'minor', 'category' => 'other', 'summary' => 'Still open']);
        $this->actingAs($this->staff(Access::FINANCE));
        Livewire::test(ListIncidents::class)->assertTableActionHidden('resolve', $open)->assertTableActionHidden('raise');
    }
}
