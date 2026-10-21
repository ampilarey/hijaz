<?php

namespace Database\Factories;

use App\Models\Island;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Island> */
class IslandFactory extends Factory
{
    public function definition(): array
    {
        return [
            'atoll_code' => 'K',
            'atoll_name' => 'Kaafu',
            'name' => fake()->unique()->lexify('??????dhoo'),
            'is_active' => true,
        ];
    }
}
