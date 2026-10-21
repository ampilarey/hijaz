<?php

namespace App\Filament\Resources\ExcursionBookings\Pages;

use App\Filament\Resources\ExcursionBookings\ExcursionBookingResource;
use Filament\Resources\Pages\ListRecords;

class ListExcursionBookings extends ListRecords
{
    protected static string $resource = ExcursionBookingResource::class;
}
