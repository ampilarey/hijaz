<?php

namespace App\Listeners;

use App\Events\IdentityMismatchFound;
use App\Services\Telegram\Alerts;
use Illuminate\Contracts\Queue\ShouldQueue;

/** Tells whoever verifies pilgrims that an ID card and a passport disagree. */
class AlertOfIdentityMismatch implements ShouldQueue
{
    public function __construct(private readonly Alerts $alerts) {}

    public function handle(IdentityMismatchFound $event): void
    {
        $lines = ['<b>ID card and passport disagree</b>', '<b>'.e($event->pilgrim->name_en).'</b>'];

        foreach ($event->mismatches as $m) {
            $lines[] = ucfirst(str_replace('_', ' ', (string) $m['field'])).': ID card says '.e((string) ($m['id_card'] ?? '—')).', passport says '.e((string) ($m['passport'] ?? '—')).'.';
        }

        $lines[] = '';
        $lines[] = 'Correct a reading or explain it: '.e(Alerts::portalUrl('pilgrims/'.$event->pilgrim->getKey().'/edit'));

        $this->alerts->tell('pilgrim.verify', implode("\n", $lines), [], $event->byUserId);
    }
}
