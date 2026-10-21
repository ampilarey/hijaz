<?php

namespace App\Exceptions;

use App\Models\Trip;
use RuntimeException;

class NoSeatsAvailable extends RuntimeException
{
    public static function on(Trip $trip, int $wanted, int $remaining): self
    {
        return new self("{$trip->name} has {$remaining} seat".($remaining === 1 ? '' : 's')." left and {$wanted} were asked for.");
    }

    public static function becauseCapacityIsUnset(Trip $trip): self
    {
        return new self("{$trip->name} has no seats set. Enter how many the trip can take first.");
    }
}
