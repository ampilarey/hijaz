<?php

namespace App\Events;

use App\Models\Payment;
use Illuminate\Foundation\Events\Dispatchable;

/** A payment was verified, or a refund made: money really moved, and the books hear of it. */
class PaymentSettled
{
    use Dispatchable;

    public function __construct(public readonly Payment $payment) {}
}
