<?php

namespace App\Exceptions;

use RuntimeException;

class IllegalTripTransition extends RuntimeException
{
    public static function from(string $from, string $to): self
    {
        return new self("A trip cannot go from {$from} to {$to}.");
    }

    public static function becauseDatesAreUnset(): self
    {
        return new self('Set the departure and return dates first: a trip without them stays a draft.');
    }
}
