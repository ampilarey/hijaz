<?php

return [

    /*
    | An authenticator app is optional: anyone may set one up from their
    | profile. Set this to true to make Admin and Finance accounts set one up
    | before the portal lets them in.
    */
    'second_factor_required' => (bool) env('PORTAL_SECOND_FACTOR_REQUIRED', false),

];
