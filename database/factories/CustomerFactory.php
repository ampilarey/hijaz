<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Customer> */
class CustomerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'type' => Customer::INDIVIDUAL,
            'name' => fake()->name(),
            'phone' => '7'.fake()->numerify('######'),
        ];
    }

    public function family(): static
    {
        return $this->state(fn (): array => ['type' => Customer::FAMILY, 'name' => fake()->lastName().' family']);
    }
}
