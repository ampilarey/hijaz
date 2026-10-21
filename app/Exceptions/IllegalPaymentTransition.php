<?php

namespace App\Exceptions;

use RuntimeException;

class IllegalPaymentTransition extends RuntimeException
{
    public static function from(string $from, string $to): self
    {
        return new self("A payment cannot go from {$from} to {$to}. Money that was received is reversed by a refund, not by editing.");
    }
}
