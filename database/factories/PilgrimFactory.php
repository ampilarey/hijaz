<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Pilgrim;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Pilgrim> */
class PilgrimFactory extends Factory
{
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'relationship_to_head' => 'self',
            'name_en' => strtoupper(fake()->firstName().' '.fake()->lastName()),
            'gender' => fake()->randomElement(['male', 'female']),
            'date_of_birth' => fake()->dateTimeBetween('-70 years', '-18 years')->format('Y-m-d'),
            'id_number' => 'A'.fake()->unique()->numerify('######'),
            'phone' => '7'.fake()->numerify('######'),
        ];
    }

    public function withPassport(?string $expires = null): static
    {
        return $this->state(fn (): array => [
            'passport_number' => 'P'.fake()->unique()->numerify('#######'),
            'passport_expires_on' => $expires ?? now()->addYears(5)->toDateString(),
            'passport_issuing_country' => 'MDV',
        ]);
    }
}
