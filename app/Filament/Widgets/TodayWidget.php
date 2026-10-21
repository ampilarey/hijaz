<?php

namespace App\Filament\Widgets;

use App\Models\Trip;
use App\Models\TripChecklistItem;
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
            'trips' => $user->can('trip.viewAny') ? Trip::query()->upcoming()->limit(5)->get() : collect(),
            'overdue' => $user->can('checklist.viewAny')
                ? TripChecklistItem::query()->open()->whereDate('due_on', '<', today())->whereHas('trip', fn ($q) => $q->live())->with('trip')->orderBy('due_on')->limit(10)->get()
                : collect(),
        ];
    }
}
