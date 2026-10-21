<?php

namespace App\Console\Commands;

use App\Services\Finance\Books;
use Illuminate\Console\Command;

/** Any verified payment the books missed (a failed request, a deploy mid-way) is posted. Safe to run daily and twice. */
class PostPayments extends Command
{
    protected $signature = 'ledger:post-payments';

    protected $description = 'Post verified payments and refunds that are not yet in the books';

    public function handle(Books $books): int
    {
        $this->info('Posted '.$books->postMissingPayments().' entries.');

        return self::SUCCESS;
    }
}
