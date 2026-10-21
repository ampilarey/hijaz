<?php

namespace App\Events;

use App\Models\Incident;
use Illuminate\Foundation\Events\Dispatchable;

/** Something went wrong on the ground; the office should hear at once. */
class IncidentRaised
{
    use Dispatchable;

    public function __construct(public readonly Incident $incident) {}
}
