<?php

namespace Database\Factories;

use App\Models\ExchangeRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ExchangeRate> */
class ExchangeRateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'from_currency' => 'USD',
            'to_currency' => 'MVR',
            'rate' => '15.42000000',
            'as_of' => now()->toDateString(),
            'source' => 'BML counter',
        ];
    }
}
