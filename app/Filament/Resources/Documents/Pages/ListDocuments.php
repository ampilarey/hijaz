<?php

namespace App\Filament\Resources\Documents\Pages;

use App\Filament\Resources\Documents\DocumentResource;
use App\Models\DocumentExtraction;
use App\Support\Settings;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListDocuments extends ListRecords
{
    protected static string $resource = DocumentResource::class;

    public function getTabs(): array
    {
        $proposed = fn (Builder $query) => $query->whereHas('versions', fn ($v) => $v->whereNull('superseded_at')->whereHas('extractions', fn ($e) => $e->where('status', DocumentExtraction::PROPOSED)));

        return [
            'accept' => Tab::make('To accept')->modifyQueryUsing(fn (Builder $query) => $query->pending()->where($proposed)),
            'verify' => Tab::make('To verify')->modifyQueryUsing(fn (Builder $query) => $query->pending()->whereNot($proposed)),
            'expiring' => Tab::make('Expiring')->modifyQueryUsing(fn (Builder $query) => $query->whereNotNull('expires_at')
                ->whereDate('expires_at', '<', today()->addMonths((int) Settings::get(Settings::PASSPORT_VALIDITY_MONTHS) + 1))),
            'all' => Tab::make('All'),
        ];
    }
}
