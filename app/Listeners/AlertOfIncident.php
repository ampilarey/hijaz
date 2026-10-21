<?php

namespace App\Listeners;

use App\Events\IncidentRaised;
use App\Models\Incident;
use App\Services\Telegram\Alerts;
use Illuminate\Contracts\Queue\ShouldQueue;

/** Serious and emergency incidents go to everyone who may see incidents, at once. Minor ones wait for the morning summary. */
class AlertOfIncident implements ShouldQueue
{
    public function __construct(private readonly Alerts $alerts) {}

    public function handle(IncidentRaised $event): void
    {
        $i = $event->incident->fresh(['trip', 'bookingPilgrim.pilgrim', 'recorder']);

        if (! $i instanceof Incident || $i->severity === Incident::MINOR) {
            return;
        }

        $text = implode("\n", array_filter([
            ($i->severity === Incident::EMERGENCY ? '🚨 <b>EMERGENCY</b>' : '⚠ <b>Serious incident</b>').' · '.e($i->trip->code),
            '<b>'.e($i->summary).'</b>',
            e($i->categoryLabel()).($i->bookingPilgrim ? ' · '.e($i->bookingPilgrim->pilgrim->name_en) : '').($i->location ? ' · '.e($i->location) : ''),
            $i->detail ? e(mb_substr($i->detail, 0, 300)) : null,
            'Raised by '.e($i->recorder?->name ?? 'the leader').' at '.e($i->happened_at->format('H:i, j M')).' · '.e($i->reference),
            '',
            'Portal: '.e(Alerts::portalUrl('incidents')),
        ]));

        $this->alerts->tell('incident.viewAny', $text, [], $i->recorded_by);
    }
}
