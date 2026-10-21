<?php

return [

    // A draft booking keeps its seats this long before they go back to the pool.
    'hold_days' => (int) env('BOOKING_HOLD_DAYS', 7),

    'reference_prefix' => 'HJZ',

    // Pax types by age on the departure day.
    'infant_under' => 2,
    'child_under' => 12,

];
