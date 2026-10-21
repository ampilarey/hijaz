<?php

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

/**
 * No update and no delete verb: a payment is appended, reviewed, and if
 * wrong reversed by a refund. The verbs below are the review.
 */
class PaymentPolicy
{
    use ChecksPermissions;

    protected function resource(): string
    {
        return 'payment';
    }

    public function verify(User $user): bool
    {
        return $user->can('payment.verify');
    }

    public function refund(User $user): bool
    {
        return $user->can('payment.refund');
    }

    public function download(User $user): bool
    {
        return $user->can('payment.download');
    }
}
