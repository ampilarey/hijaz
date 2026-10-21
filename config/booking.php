<?php

return [

    // A draft booking keeps its seats this long before they go back to the pool.
    'hold_days' => (int) env('BOOKING_HOLD_DAYS', 7),

    'reference_prefix' => 'HJZ',

    // Pax types by age on the departure day.
    'infant_under' => 2,
    'child_under' => 12,

    // A child in a hotel room needs an adult from the same booking in it.
    'room_child_under' => 16,

    // Days after a visa is submitted before it counts as late.
    'visa_sla_days' => (int) env('VISA_SLA_DAYS', 7),

    // How long a customer's one-time link to their booking page stays open.
    'self_service_days' => (int) env('SELF_SERVICE_LINK_DAYS', 30),

];
