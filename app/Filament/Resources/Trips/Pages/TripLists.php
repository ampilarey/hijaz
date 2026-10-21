<?php

namespace App\Filament\Resources\Trips\Pages;

use App\Filament\Resources\Trips\TripResource;
use App\Models\Trip;
use App\Services\Operations\Lists;
use Filament\Facades\Filament;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

/** Every list, manifest, card and tag for the trip, one tap each, as PDF or CSV. */
class TripLists extends Page
{
    use InteractsWithRecord;

    protected static string $resource = TripResource::class;

    protected string $view = 'filament.resources.trips.lists';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        abort_unless(Filament::auth()->user()?->can('list.view'), 403);
    }

    public function getTitle(): string|Htmlable
    {
        return 'Lists · '.$this->getRecord()->code;
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        /** @var Trip $trip */
        $trip = $this->getRecord();

        return [
            'trip' => $trip,
            'presets' => Lists::PRESETS,
            'buses' => $trip->busGroups()->withCount('members')->get(),
            'hotels' => $trip->hotels()->orderBy('check_in_on')->withCount('rooms')->get(),
            'excursions' => $trip->excursions()->get(),
            'canPrintCards' => (bool) Filament::auth()->user()?->can('card.create'),
            'travellers' => $trip->travellers()->count(),
        ];
    }
}
