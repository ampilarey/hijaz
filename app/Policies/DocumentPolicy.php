<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

/** No delete verb: a wrong upload is superseded by the right one, and both stay. */
class DocumentPolicy
{
    use ChecksPermissions;

    protected function resource(): string
    {
        return 'document';
    }

    /** A bank slip is part of its payment: whoever may see the payment may open the slip. */
    public function download(User $user, ?Document $document = null): bool
    {
        if ($document?->type === Document::BANK_SLIP) {
            return $user->can('payment.view');
        }

        return $user->can('document.download');
    }

    public function accept(User $user): bool
    {
        return $user->can('document.accept');
    }

    public function verify(User $user): bool
    {
        return $user->can('document.verify');
    }
}
