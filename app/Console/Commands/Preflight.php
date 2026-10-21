<?php

namespace App\Console\Commands;

use App\Services\Documents\ReaderManager;
use App\Services\Telegram\Bot;
use App\Support\Access;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Spatie\Permission\Models\Role;

/**
 * Is this installation fit to serve? Run by the deploy script after every
 * deploy and by hand in cPanel Terminal. Each check answers a question
 * somebody would otherwise ask after the fact.
 */
class Preflight extends Command
{
    protected $signature = 'hijaz:preflight {--production : Apply the checks that only matter on the live site}';

    protected $description = 'Check this installation is fit to serve';

    /** Written every minute by the schedule (routes/console.php). */
    public const SCHEDULE_HEARTBEAT = 'hijaz.schedule.heartbeat';

    private const SCHEDULE_STALE_MINUTES = 15;

    /** @var list<array{string, string}> */
    private array $failures = [];

    /** @var list<array{string, string}> */
    private array $warnings = [];

    public function handle(): int
    {
        $production = $this->option('production') || app()->isProduction();

        $this->checkDatabase();
        $this->checkRoles();
        $this->checkBuild();
        $this->checkFilamentAssets();
        $this->checkStorage();

        $this->checkReader();

        if ($production) {
            $this->checkProductionEnvironment();
            $this->checkScheduler();
            $this->checkTelegram();
        }

        foreach ($this->warnings as [$name, $detail]) {
            $this->line("  <fg=yellow>warn</>  {$name} — {$detail}");
        }

        foreach ($this->failures as [$name, $detail]) {
            $this->line("  <fg=red>fail</>  {$name} — {$detail}");
        }

        if ($this->failures !== []) {
            $this->newLine();
            $this->error(count($this->failures).' check(s) failed.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Preflight passed'.($this->warnings !== [] ? ' with '.count($this->warnings).' warning(s).' : '.'));

        return self::SUCCESS;
    }

    private function checkDatabase(): void
    {
        try {
            DB::connection()->getPdo();
        } catch (\Throwable $e) {
            $this->failures[] = ['database', 'cannot connect: '.$e->getMessage()];

            return;
        }

        $pending = collect(app('migrator')->getMigrationFiles(database_path('migrations')))
            ->keys()
            ->diff(app('migrator')->getRepository()->getRan())
            ->count();

        if ($pending > 0) {
            $this->warnings[] = ['migrations', "{$pending} pending"];
        }
    }

    private function checkRoles(): void
    {
        try {
            $missing = array_diff(Access::ROLES, Role::query()->pluck('name')->all());
        } catch (\Throwable) {
            return;
        }

        if ($missing !== []) {
            $this->failures[] = ['roles', 'not seeded: '.implode(', ', $missing).' — run php artisan db:seed --force'];
        }
    }

    private function checkBuild(): void
    {
        $manifest = public_path('build/manifest.json');

        if (! File::exists($manifest)) {
            $this->failures[] = ['vite build', 'public/build/manifest.json is missing; every page will throw'];

            return;
        }

        foreach (json_decode(File::get($manifest), true) ?: [] as $entry) {
            if (isset($entry['file']) && ! File::exists(public_path('build/'.$entry['file']))) {
                $this->failures[] = ['vite build', "manifest names {$entry['file']}, which is not in public/build"];
            }
        }
    }

    /** Published by `filament:upgrade` on composer dump-autoload; without them the panel renders unstyled. */
    private function checkFilamentAssets(): void
    {
        if (! File::exists(public_path('js/filament/filament/app.js')) || ! File::exists(public_path('css/filament/filament/app.css'))) {
            $this->failures[] = ['filament assets', 'not published; run php artisan filament:assets'];
        }
    }

    private function checkStorage(): void
    {
        if (! File::isWritable(storage_path('framework/views'))) {
            $this->failures[] = ['storage', 'storage/framework/views is not writable'];
        }
    }

    private function checkProductionEnvironment(): void
    {
        if (config('app.debug')) {
            $this->failures[] = ['APP_DEBUG', 'is true; a stack trace on the live site exposes configuration and file paths'];
        }

        if (config('app.env') !== 'production') {
            $this->failures[] = ['APP_ENV', 'is ['.config('app.env').']'];
        }

        if (! str_starts_with((string) config('app.url'), 'https://')) {
            $this->failures[] = ['APP_URL', 'is not https; secure cookies and the Telegram webhook depend on it'];
        }

        if (blank(config('app.key'))) {
            $this->failures[] = ['APP_KEY', 'is empty; sessions and encrypted columns cannot be read'];
        }

        if (config('session.driver') !== 'database' || config('cache.default') !== 'database') {
            $this->warnings[] = ['drivers', 'session and cache should both be database on this host'];
        }
    }

    private function checkScheduler(): void
    {
        $fix = 'run scripts/install-scheduler-cron.sh once in cPanel Terminal';

        try {
            $last = Cache::get(self::SCHEDULE_HEARTBEAT);
        } catch (\Throwable $e) {
            $this->warnings[] = ['scheduler', 'could not be checked: '.$e->getMessage()];

            return;
        }

        if (! is_int($last)) {
            $this->warnings[] = ['scheduler', "schedule:run has never run on this host — {$fix}"];

            return;
        }

        $minutes = (int) floor((now()->getTimestamp() - $last) / 60);

        if ($minutes > self::SCHEDULE_STALE_MINUTES) {
            $this->warnings[] = ['scheduler', "schedule:run last ran {$minutes} minutes ago; the cron line has stopped — {$fix}"];
        }
    }

    private function checkReader(): void
    {
        $wanted = (string) config('documents.reader', 'tesseract');
        $reader = app(ReaderManager::class)->reader();

        if ($wanted !== 'manual' && $reader->name() === 'manual') {
            $this->warnings[] = ['reader', "DOCUMENTS_READER is {$wanted} but it is not available here; copies are typed by hand (docs/PORTAL.md)"];
        } else {
            $this->line("  <fg=green>ok</>    reader — {$reader->name()}");
        }
    }

    private function checkTelegram(): void
    {
        if (! Bot::isConfigured()) {
            $this->warnings[] = ['telegram', 'TELEGRAM_BOT_TOKEN is not set; the bot is off'];
        }
    }
}
