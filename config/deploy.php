<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Deploy webhook secret
    |--------------------------------------------------------------------------
    |
    | Shared secret for POST /api/deploy/pull. Set the same value in the
    | server .env and in the GitHub Actions secret DEPLOY_WEBHOOK_SECRET
    | (environment: hijaz). Leave empty to disable the endpoint (404).
    |
    */

    'webhook_secret' => env('DEPLOY_WEBHOOK_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Hosts allowed to honour the webhook
    |--------------------------------------------------------------------------
    |
    | Compared against the request host and the APP_URL host, case-insensitive.
    | Any other host answers 404, so a copy of this code on another domain can
    | never be pulled by this repository's workflow.
    |
    */

    'allowed_hosts' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('DEPLOY_ALLOWED_HOSTS', 'hijaz.rihla.mv')),
    ))),

    /*
    |--------------------------------------------------------------------------
    | Where the pull script writes its log
    |--------------------------------------------------------------------------
    |
    | Inside the checkout, next to Laravel's own logs, so everything about
    | the site lives in one folder.
    |
    */

    'log' => env('DEPLOY_LOG', storage_path('logs/deploy.log')),

];
