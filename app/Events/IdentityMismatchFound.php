<?php

namespace App\Events;

use App\Models\Pilgrim;
use Illuminate\Foundation\Events\Dispatchable;

/** The ID card and the passport disagree on something nobody has explained. */
class IdentityMismatchFound
{
    use Dispatchable;

    /** @param  list<array<string, mixed>>  $mismatches */
    public function __construct(public readonly Pilgrim $pilgrim, public readonly array $mismatches, public readonly ?int $byUserId) {}
}
