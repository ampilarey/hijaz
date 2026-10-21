<?php

namespace Tests\Support;

use App\Models\Customer;
use App\Models\Pilgrim;
use App\Models\PriceTier;
use App\Models\Trip;

/** A trip with the usual price list, and a family to put on it. */
final class PricedTrip
{
    public static function make(int $capacity = 40): Trip
    {
        $trip = Trip::factory()->open()->create(['capacity_total' => $capacity]);

        $trip->priceTiers()->createMany([
            ['occupancy' => PriceTier::QUAD, 'pax_type' => PriceTier::ADULT, 'amount_minor' => 2850000, 'currency' => 'MVR', 'sort_order' => 1],
            ['occupancy' => PriceTier::QUAD, 'pax_type' => PriceTier::CHILD, 'amount_minor' => 2000000, 'currency' => 'MVR', 'sort_order' => 2],
            ['occupancy' => PriceTier::DOUBLE, 'pax_type' => PriceTier::ADULT, 'amount_minor' => 3200000, 'currency' => 'MVR', 'sort_order' => 3],
            ['occupancy' => PriceTier::SINGLE, 'pax_type' => PriceTier::ADULT, 'amount_minor' => 29950, 'currency' => 'USD', 'sort_order' => 4],
        ]);

        return $trip->fresh();
    }

    /** @return array{Customer, Pilgrim, Pilgrim, Pilgrim}  Head, spouse, child of nine. */
    public static function family(): array
    {
        $customer = Customer::factory()->family()->create();
        $head = Pilgrim::factory()->create(['customer_id' => $customer->id, 'relationship_to_head' => 'self', 'name_en' => 'AHMED HASSAN']);
        $spouse = Pilgrim::factory()->create(['customer_id' => $customer->id, 'relationship_to_head' => 'spouse', 'name_en' => 'AMINATH ALI']);
        $child = Pilgrim::factory()->create(['customer_id' => $customer->id, 'relationship_to_head' => 'child', 'name_en' => 'MARIYAM AHMED', 'date_of_birth' => now()->subYears(9)->toDateString()]);
        $customer->forceFill(['head_pilgrim_id' => $head->id])->save();

        return [$customer, $head, $spouse, $child];
    }
}
