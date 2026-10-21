<?php

namespace App\Policies;

use App\Policies\Concerns\ChecksPermissions;

/** No delete verb: a rate that was wrong gets a corrected row dated the same day. */
class ExchangeRatePolicy
{
    use ChecksPermissions;

    protected function resource(): string
    {
        return 'rate';
    }
}
