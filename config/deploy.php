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
    | Defaults to the directory above the checkout, which on cPanel is the
    | account's home: /home/<account>/hijaz-deploy.log.
    |
    */

    'log' => env('DEPLOY_LOG', dirname(base_path()).'/hijaz-deploy.log'),

];
