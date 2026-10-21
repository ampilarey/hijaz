<?php

namespace App\Filament\Resources\Islands\Pages;

use App\Filament\Resources\Islands\IslandResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListIslands extends ListRecords
{
    protected static string $resource = IslandResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
