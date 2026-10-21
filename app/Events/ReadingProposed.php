<?php

namespace App\Events;

use App\Models\DocumentExtraction;
use Illuminate\Foundation\Events\Dispatchable;

/** A reader proposed fields for a pilgrim's copy; somebody has to accept them. */
class ReadingProposed
{
    use Dispatchable;

    public function __construct(public readonly DocumentExtraction $extraction, public readonly ?int $byUserId) {}
}
