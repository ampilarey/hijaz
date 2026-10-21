<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when something tries to delete or demote the only remaining Admin.
 * Losing it means nobody can manage users, and the way back is
 * `php artisan portal:admin` in cPanel Terminal.
 */
class LastAdmin extends RuntimeException
{
    public function __construct(string $message = 'This is the last Admin. Give someone else that role first, or nobody will be able to manage the portal.')
    {
        parent::__construct($message);
    }
}
