<?php

namespace App\Filament\Widgets;

use App\Services\Finance\MoneyByMonth;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;

/** Money in against money out, month by month, from the books. */
class MoneyChart extends ChartWidget
{
    protected static ?int $sort = 3;

    protected static bool $isLazy = false;

    protected ?string $heading = 'Money in and out';

    protected ?string $description = 'Last six months, in MVR at each entry\'s own rate';

    protected ?string $maxHeight = '260px';

    protected int|string|array $columnSpan = ['default' => 'full', 'lg' => 1];

    public static function canView(): bool
    {
        return (bool) Filament::auth()->user()?->can('ledger.viewAny');
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $months = MoneyByMonth::forLastMonths(6);

        return [
            'datasets' => [
                ['label' => 'In', 'data' => array_map(fn (array $m): float => $m['in'] / 100, array_values($months)), 'backgroundColor' => '#01a85a', 'borderRadius' => 4],
                ['label' => 'Out', 'data' => array_map(fn (array $m): float => $m['out'] / 100, array_values($months)), 'backgroundColor' => '#ec3237', 'borderRadius' => 4],
            ],
            'labels' => array_column($months, 'label'),
        ];
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['position' => 'bottom']],
            'scales' => ['y' => ['beginAtZero' => true, 'ticks' => ['maxTicksLimit' => 5]]],
        ];
    }
}
