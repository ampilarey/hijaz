<?php

namespace Database\Factories;

use App\Models\Trip;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Trip> */
class TripFactory extends Factory
{
    public function definition(): array
    {
        $departs = now()->addMonths(3)->startOfMonth()->addDays(9);

        return [
            'name' => $departs->format('F Y').' Umrah',
            'departs_on' => $departs->toDateString(),
            'returns_on' => $departs->copy()->addDays(14)->toDateString(),
            'capacity_total' => 40,
        ];
    }

    public function open(): static
    {
        return $this->state(fn (): array => ['status' => Trip::OPEN]);
    }
}
