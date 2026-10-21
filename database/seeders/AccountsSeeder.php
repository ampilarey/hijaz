<?php

namespace Database\Seeders;

use App\Models\Account;
use Illuminate\Database\Seeder;

/** The four accounts every Hijaz has. Numbers are entered by Finance in the portal. */
class AccountsSeeder extends Seeder
{
    public function run(): void
    {
        if (Account::query()->exists()) {
            return;
        }

        $defaults = [
            ['Bank MVR', Account::BANK, 'MVR', 1],
            ['Bank USD', Account::BANK, 'USD', 2],
            ['Office cash MVR', Account::CASH, 'MVR', 3],
            ['Office cash USD', Account::CASH, 'USD', 4],
        ];

        foreach ($defaults as [$name, $kind, $currency, $sort]) {
            Account::query()->create(['name' => $name, 'kind' => $kind, 'currency' => $currency, 'sort_order' => $sort]);
        }
    }
}
