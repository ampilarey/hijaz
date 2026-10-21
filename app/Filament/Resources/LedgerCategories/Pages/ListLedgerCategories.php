<?php

namespace App\Filament\Resources\LedgerCategories\Pages;

use App\Filament\Resources\LedgerCategories\LedgerCategoryResource;
use Filament\Resources\Pages\ListRecords;

class ListLedgerCategories extends ListRecords
{
    protected static string $resource = LedgerCategoryResource::class;
}
