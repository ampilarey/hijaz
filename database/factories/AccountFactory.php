<?php

namespace Database\Factories;

use App\Models\Account;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Account> */
class AccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Bank MVR',
            'kind' => Account::BANK,
            'currency' => 'MVR',
            'bank' => 'Bank of Maldives',
            'account_name' => 'Hijaz Hajj & Umrah',
            'account_number' => (string) fake()->numerify('7730000#######'),
            'is_active' => true,
        ];
    }
}
