<?php

namespace App\Filament\Support;

use App\Models\Trip;

final class TripOptions
{
    /** @return array<int, string> */
    public static function all(bool $liveOnly = false): array
    {
        return Trip::query()
            ->when($liveOnly, fn ($q) => $q->live())
            ->orderByDesc('departs_on')
            ->limit(100)
            ->get()
            ->mapWithKeys(fn (Trip $trip): array => [$trip->getKey() => $trip->label])
            ->all();
    }
}
