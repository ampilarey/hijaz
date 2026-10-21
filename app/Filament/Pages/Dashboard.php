<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\TodayWidget;
use Filament\Pages\Dashboard as BaseDashboard;

/**
 * "Today": what needs doing. Each phase adds its queue to the widget.
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
        return [TodayWidget::class];
    }

    public function getColumns(): int|array
    {
        return 1;
    }
}
