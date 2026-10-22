<?php

namespace App\Filament\Support;

use App\Models\Trip;

final class TripOptions
{
    /** @return array<int, string> */
    public static function all(bool $liveOnly = false, bool $datedOnly = false): array
    {
        return Trip::query()
            ->when($liveOnly, fn ($q) => $q->live())
            ->when($datedOnly, fn ($q) => $q->whereNotNull('departs_on')->whereNotNull('returns_on'))
            ->orderByDesc('departs_on')
            ->limit(100)
            ->get()
            ->mapWithKeys(fn (Trip $trip): array => [$trip->getKey() => $trip->label])
            ->all();
    }
}
