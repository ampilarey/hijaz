<?php

namespace App\Filament\Resources\Pilgrims\Pages;

use App\Filament\Resources\Pilgrims\PilgrimResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPilgrims extends ListRecords
{
    protected static string $resource = PilgrimResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('New pilgrim')];
    }
}
