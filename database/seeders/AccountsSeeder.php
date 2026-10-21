<?php

namespace Database\Seeders;

use App\Models\Account;
use Illuminate\Database\Seeder;

/**
 * The accounts every Hijaz has, made once each by name and never touched
 * again, so Finance's edits (numbers, names) stick. Leader floats are added
 * by Finance per trip.
 */
class AccountsSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            ['Bank MVR', Account::BANK, 'MVR', 1],
            ['Bank USD', Account::BANK, 'USD', 2],
            ['Office cash MVR', Account::CASH, 'MVR', 3],
            ['Office cash USD', Account::CASH, 'USD', 4],
            ['Office cash SAR', Account::CASH, 'SAR', 5],
        ];

        foreach ($defaults as [$name, $kind, $currency, $sort]) {
            if (Account::query()->where('kind', $kind)->where('currency', $currency)->exists()) {
                continue;
            }

            Account::query()->create(['name' => $name, 'kind' => $kind, 'currency' => $currency, 'sort_order' => $sort]);
        }
    }
}
