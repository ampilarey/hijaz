<?php

namespace App\Listeners;

use App\Events\ReadingProposed;
use App\Models\Document;
use App\Models\Pilgrim;
use App\Services\Telegram\Alerts;
use App\Services\Telegram\Bot;
use Illuminate\Contracts\Queue\ShouldQueue;

/** Tells whoever accepts readings that one is waiting, with a button to accept it from the chat. */
class AlertOfProposedReading implements ShouldQueue
{
    public function __construct(private readonly Alerts $alerts) {}

    public function handle(ReadingProposed $event): void
    {
        $x = $event->extraction->fresh(['version.document.documentable']);

        if ($x === null || ! $x->isProposed() || ($x->fields ?? []) === []) {
            return;
        }

        $document = $x->version->document;
        $pilgrim = $document->documentable;

        if (! $pilgrim instanceof Pilgrim) {
            return;
        }

        $text = implode("\n", [
            '<b>Reading to accept</b>',
            e(Document::TYPES[$document->type] ?? $document->type).' of <b>'.e($pilgrim->name_en).'</b> · '.count($x->fields).' fields read by '.e($x->reader),
            $document->type === Document::PASSPORT ? ($x->mrz_valid === true ? 'MRZ check digits pass.' : ($x->mrz_valid === false ? '⚠ MRZ check digits FAIL: check every field in the portal.' : 'No MRZ read.')) : null,
            '',
            'Portal: '.e(Alerts::portalUrl('pilgrims/'.$pilgrim->getKey().'/edit')),
        ]);

        $buttons = $document->type === Document::PASSPORT && $x->mrz_valid !== true
            ? []
            : Bot::keyboard([['doc.accept:'.$x->getKey() => 'Accept as read', 'doc.show:'.$x->getKey() => 'Show the fields']]);

        $this->alerts->tell('document.accept', $text, $buttons, $event->byUserId);
    }
}
