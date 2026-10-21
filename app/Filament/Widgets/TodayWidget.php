<?php

namespace App\Filament\Widgets;

use App\Models\User;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;

class TodayWidget extends Widget
{
    protected string $view = 'filament.widgets.today';

    protected int|string|array $columnSpan = 'full';

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        /** @var User $user */
        $user = Filament::auth()->user();

        return [
            'user' => $user,
            'roles' => $user->getRoleNames()->all(),
            'secondFactor' => $user->hasSecondFactor(),
            'telegramLinked' => $user->telegramLink()->exists(),
            'today' => now()->translatedFormat('l, j F Y'),
        ];
    }
}
