<?php

namespace App\Listeners;

use App\Events\PaymentSettled;
use App\Services\Finance\Books;

/** Money verified is money in the books, at once and in the same request. */
class PostPaymentToBooks
{
    public function __construct(private readonly Books $books) {}

    public function handle(PaymentSettled $event): void
    {
        $this->books->postPayment($event->payment->fresh(['payable', 'account', 'reviewer']));
    }
}
