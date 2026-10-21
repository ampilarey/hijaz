<?php

use App\Console\Commands\Preflight;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

/*
| cPanel cannot keep a queue worker alive, so the scheduler (cron, once a
| minute: scripts/install-scheduler-cron.sh) drains the database queue
| instead. Jobs may start up to a minute late; write them so that is fine.
| Every entry is idempotent and safe to run late, and the overlap lock
| expires quickly so a killed run cannot hold the next one off for a day.
*/

Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=3')
    ->everyMinute()
    ->withoutOverlapping(5);

// Proof the scheduler is alive, read by hijaz:preflight.
Schedule::call(fn () => Cache::forever(Preflight::SCHEDULE_HEARTBEAT, now()->getTimestamp()))
    ->everyMinute()
    ->name('schedule-heartbeat');

// A nightly copy, pruned, in case a deploy's own backup is ever the only other one.
Schedule::command('hijaz:backup --keep=14')
    ->dailyAt('02:30')
    ->withoutOverlapping(30);

// The morning summary to every linked staff member.
Schedule::command('telegram:daily')
    ->dailyAt((string) config('telegram.daily_summary_at', '08:00'))
    ->withoutOverlapping(10);

// Seats held for a draft booking go back to the pool once the hold lapses.
Schedule::command('bookings:expire-holds')
    ->hourly()
    ->withoutOverlapping(10);

// Verified money the books missed, caught up nightly.
Schedule::command('ledger:post-payments')
    ->dailyAt('03:10')
    ->withoutOverlapping(10);
