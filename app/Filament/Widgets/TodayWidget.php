<?php

namespace App\Filament\Widgets;

use App\Models\Document;
use App\Models\Pilgrim;
use App\Models\Trip;
use App\Models\TripChecklistItem;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;

class TodayWidget extends Widget
{
    protected string $view = 'filament.widgets.today';

    /** Rendered with the page, not lazily after it: the queues are the page. */
    protected static bool $isLazy = false;

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
            'toAccept' => $user->can('document.viewAny') ? Document::query()->forPilgrims()->pending()->whereHas('versions', fn ($v) => $v->whereNull('superseded_at')->whereHas('extractions', fn ($e) => $e->where('status', 'proposed')))->count() : 0,
            'toVerify' => $user->can('document.verify') ? Document::query()->forPilgrims()->pending()->whereDoesntHave('versions', fn ($v) => $v->whereNull('superseded_at')->whereHas('extractions', fn ($e) => $e->where('status', 'proposed')))->count() : 0,
            'mismatches' => $user->can('pilgrim.viewAny') ? Pilgrim::query()->whereNotNull('identity_mismatches')->where('identity_mismatches', 'like', '%"explained":false%')->count() : 0,
            'unverified' => $user->can('pilgrim.viewAny') ? Pilgrim::query()->where('registration_status', Pilgrim::REGISTERED)->count() : 0,
            'overdue' => $user->can('checklist.viewAny')
                ? TripChecklistItem::query()->open()->whereDate('due_on', '<', today())->whereHas('trip', fn ($q) => $q->live())->with('trip')->orderBy('due_on')->limit(10)->get()
                : collect(),
        ];
    }
}
