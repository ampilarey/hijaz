<?php

namespace App\Filament\Widgets;

use App\Models\Booking;
use App\Models\Document;
use App\Models\Payment;
use App\Models\PaymentSchedule;
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
            'slipsWaiting' => $user->can('payment.verify') ? Payment::query()->awaitingReview()->count() : 0,
            'holdsExpiring' => $user->can('booking.viewAny')
                ? Booking::query()->where('status', Booking::DRAFT)->whereHas('seatHolds', fn ($h) => $h->whereNull('released_at')->whereBetween('expires_at', [now(), now()->addDays(2)]))->count()
                : 0,
            'instalmentsOverdue' => $user->can('booking.viewAny')
                ? PaymentSchedule::query()->whereDate('due_on', '<', today())->whereHas('booking', fn ($b) => $b->where('status', Booking::CONFIRMED))->with('booking')->get()->filter(fn (PaymentSchedule $s): bool => $s->isOverdue())->count()
                : 0,
            'overdue' => $user->can('checklist.viewAny')
                ? TripChecklistItem::query()->open()->whereDate('due_on', '<', today())->whereHas('trip', fn ($q) => $q->live())->with('trip')->orderBy('due_on')->limit(10)->get()
                : collect(),
        ];
    }
}
