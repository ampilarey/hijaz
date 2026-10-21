<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// cPanel cannot keep a queue worker alive, so the scheduler (cron, once a
// minute: scripts/install-scheduler-cron.sh) drains the database queue
// instead. Jobs may therefore start up to a minute late; write them so that
// is fine.
Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=3')
    ->everyMinute()
    ->withoutOverlapping();
