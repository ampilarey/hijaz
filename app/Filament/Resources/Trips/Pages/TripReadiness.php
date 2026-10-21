<?php

namespace App\Filament\Resources\Trips\Pages;

use App\Filament\Resources\Trips\TripResource;
use App\Models\Trip;
use App\Services\Operations\Readiness;
use Filament\Facades\Filament;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

/** The board the owner opens every morning: what still blocks this trip, and what merely needs attention. */
class TripReadiness extends Page
{
    use InteractsWithRecord;

    protected static string $resource = TripResource::class;

    protected string $view = 'filament.resources.trips.readiness';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        abort_unless(Filament::auth()->user()?->can('readiness.view'), 403);
    }

    public function getTitle(): string|Htmlable
    {
        return 'Readiness · '.$this->getRecord()->code;
    }

    public function getSubheading(): ?string
    {
        /** @var Trip $trip */
        $trip = $this->getRecord();

        return $trip->name.' · departs '.$trip->departs_on->format('j M Y').' · '.$trip->departs_on->diffForHumans();
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        /** @var Trip $trip */
        $trip = $this->getRecord();
        $readiness = app(Readiness::class);
        $concerns = $readiness->concerns($trip);
        $confirmed = $readiness->confirmed($trip);

        return [
            'trip' => $trip,
            'concerns' => $concerns,
            'blocking' => array_values(array_filter($concerns, fn (array $c): bool => $c['severity'] === Readiness::BLOCKING)),
            'attention' => array_values(array_filter($concerns, fn (array $c): bool => $c['severity'] === Readiness::ATTENTION)),
            'confirmedCount' => $confirmed->count(),
            'draftCount' => $trip->bookings()->where('status', 'draft')->withCount('pilgrims')->get()->sum('pilgrims_count'),
        ];
    }
}
