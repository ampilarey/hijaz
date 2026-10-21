<?php

namespace App\Models;

/** Something money can be paid against: a booking, an excursion booking. */
interface TakesPayments
{
    /** @return list<string> The currencies this payable keeps a balance in. */
    public function paymentCurrencies(): array;

    /** @param  array<string, int>  $paidByCurrency  Minor units per currency, from succeeded allocations. */
    public function storePaid(array $paidByCurrency): void;

    public function payableLabel(): string;
}
