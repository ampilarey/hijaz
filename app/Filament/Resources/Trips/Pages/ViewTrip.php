<?php

namespace App\Filament\Resources\Trips\Pages;

use App\Filament\Resources\Trips\TripResource;
use App\Models\Trip;
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
}
