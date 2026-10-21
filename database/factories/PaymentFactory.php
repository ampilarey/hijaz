<?php

namespace Database\Factories;

use App\Models\Booking;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Payment> */
class PaymentFactory extends Factory
{
    public function definition(): array
    {
        $booking = Booking::factory()->create();

        return [
            'payable_type' => Booking::class,
            'payable_id' => $booking->id,
            'method' => Payment::BANK_TRANSFER,
            'currency' => 'MVR',
            'amount_minor' => 1000000,
            'paid_on' => now()->toDateString(),
        ];
    }
}
