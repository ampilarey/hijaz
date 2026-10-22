<?php

namespace App\Filament\Widgets;

use App\Models\Trip;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;

/** How full each trip ahead is: confirmed, still held by a draft, and free. */
class SeatsChart extends ChartWidget
{
    protected static ?int $sort = 4;

    protected static bool $isLazy = false;

    protected ?string $heading = 'Seats by trip';

    protected ?string $description = 'Trips not yet departed';

    protected ?string $maxHeight = '260px';

    protected int|string|array $columnSpan = ['default' => 'full', 'lg' => 1];

    public static function canView(): bool
    {
        return (bool) Filament::auth()->user()?->can('trip.viewAny');
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $trips = Trip::query()->live()->whereDate('departs_on', '>=', today())->orderBy('departs_on')->limit(6)->get();

        return [
            'datasets' => [
                ['label' => 'Confirmed', 'data' => $trips->map(fn (Trip $t): int => $t->capacity_confirmed)->all(), 'backgroundColor' => '#017a42'],
                ['label' => 'Held', 'data' => $trips->map(fn (Trip $t): int => $t->capacity_held)->all(), 'backgroundColor' => '#f59e0b'],
                ['label' => 'Free', 'data' => $trips->map(fn (Trip $t): int => max(0, $t->capacity_total - $t->seatsTaken()))->all(), 'backgroundColor' => '#c7d9f1'],
            ],
            'labels' => $trips->map(fn (Trip $t): string => $t->code)->all(),
        ];
    }

    protected function getOptions(): array
    {
        return [
            'indexAxis' => 'y',
            'plugins' => ['legend' => ['position' => 'bottom']],
            'scales' => ['x' => ['stacked' => true, 'beginAtZero' => true], 'y' => ['stacked' => true]],
        ];
    }
}
