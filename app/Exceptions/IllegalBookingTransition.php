<?php

namespace App\Exceptions;

use RuntimeException;

class IllegalBookingTransition extends RuntimeException
{
    public static function from(string $from, string $to): self
    {
        return new self("A booking cannot go from {$from} to {$to}.");
    }
}
