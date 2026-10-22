<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\DashboardStats;
use App\Filament\Widgets\MoneyChart;
use App\Filament\Widgets\SeatsChart;
use App\Filament\Widgets\TodayWidget;
use Filament\Pages\Dashboard as BaseDashboard;

/**
 * "Today", the page after sign-in: the figures at a glance, then what needs
 * doing, then the two charts. Each widget checks the role itself.
 */
class Dashboard extends BaseDashboard
{
    protected static ?string $title = 'Today';

    public static function getNavigationLabel(): string
    {
        return 'Today';
    }

    public function getWidgets(): array
    {
        return [DashboardStats::class, TodayWidget::class, MoneyChart::class, SeatsChart::class];
    }

    /** One column on a phone; the charts sit side by side from a laptop up. */
    public function getColumns(): int|array
    {
        return ['default' => 1, 'lg' => 2];
    }
}
