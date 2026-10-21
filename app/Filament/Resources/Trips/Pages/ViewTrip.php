<?php

namespace App\Filament\Resources\Trips\Pages;

use App\Filament\Resources\Trips\TripResource;
use App\Models\Trip;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

/**
 * The trip for staff who may look but not change it: Finance adds costs,
 * a group leader ticks the checklist, everyone else reads.
 */
class ViewTrip extends ViewRecord
{
    protected static string $resource = TripResource::class;

    public function getSubheading(): ?string
    {
        /** @var Trip $trip */
        $trip = $this->getRecord();
        $route = $trip->routeSummary();

        return $trip->statusLabel().' · '.$trip->seatsTaken().' of '.$trip->capacity_total.' seats'.($route ? ' · '.$route : '');
    }

    protected function getHeaderActions(): array
    {
        /** @var Trip $trip */
        $trip = $this->getRecord();

        return [
            Action::make('readiness')->label('Readiness')->icon('heroicon-o-clipboard-document-check')->color('gray')
                ->visible(fn (): bool => (bool) auth()->user()?->can('readiness.view'))
                ->url(TripResource::getUrl('readiness', ['record' => $trip])),
            Action::make('lists')->label('Lists')->icon('heroicon-o-document-text')->color('gray')
                ->visible(fn (): bool => (bool) auth()->user()?->can('list.view'))
                ->url(TripResource::getUrl('lists', ['record' => $trip])),
        ];
    }
}
