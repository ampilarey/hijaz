<?php

return [

    /*
    | Admin and Finance accounts must set up an authenticator app before the
    | portal lets them in. Off only for a first login on a phone that cannot
    | install one yet; turn it back on the same day.
    */
    'second_factor_required' => (bool) env('PORTAL_SECOND_FACTOR_REQUIRED', true),

];
