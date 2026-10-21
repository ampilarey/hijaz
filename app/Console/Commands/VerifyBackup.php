<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * Check that the newest backup is something you could actually restore: the
 * gzip is whole, mysqldump wrote its completion marker, and every live table
 * is in the file. A truncated dump looks right until the day it is needed.
 */
class VerifyBackup extends Command
{
    protected $signature = 'hijaz:backup:verify
                            {file? : A specific backup to check (default: the newest)}
                            {--path= : Where backups live (default storage/app/backups)}
                            {--connection= : The live connection to compare against}';

    protected $description = 'Check the newest backup is complete and restorable';

    /** Tables allowed to differ between the dump and now. */
    private const VOLATILE = ['sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'telegram_updates'];

    public function handle(): int
    {
        $directory = $this->option('path') ?: storage_path('app/backups');
        $backup = $this->argument('file') ?: $this->newestIn($directory);

        if ($backup === null || ! File::exists($backup)) {
            $this->error('No backup found. Run hijaz:backup first.');

            return self::FAILURE;
        }

        $this->line('Checking '.basename($backup).' ('.number_format(File::size($backup) / 1024).' KB)');

        return str_ends_with($backup, '.gz') ? $this->verifyMysqlDump($backup) : $this->verifySqlite($backup);
    }

    private function newestIn(string $directory): ?string
    {
        if (! File::isDirectory($directory)) {
            return null;
        }

        $files = collect(File::files($directory))
            ->filter(fn ($file) => str_starts_with($file->getFilename(), 'hijaz-'))
            ->sortByDesc(fn ($file) => $file->getMTime());

        return $files->isEmpty() ? null : $files->first()->getPathname();
    }

    private function verifySqlite(string $backup): int
    {
        config(['database.connections.backup_verify' => [
            'driver' => 'sqlite', 'database' => $backup, 'prefix' => '', 'foreign_key_constraints' => false,
        ]]);
        DB::purge('backup_verify');

        try {
            $integrity = DB::connection('backup_verify')->select('PRAGMA integrity_check');
        } catch (\Throwable $e) {
            $this->error('The backup will not open: '.$e->getMessage());

            return self::FAILURE;
        }

        $result = $integrity[0]->integrity_check ?? 'unknown';

        if ($result !== 'ok') {
            $this->error("SQLite reports the file is damaged: {$result}");

            return self::FAILURE;
        }

        $this->line('  integrity_check: ok');

        return $this->compareTables(fn (string $table): int => DB::connection('backup_verify')->table($table)->count());
    }

    private function verifyMysqlDump(string $backup): int
    {
        $gzip = new Process(['gzip', '-t', $backup]);
        $gzip->run();

        if (! $gzip->isSuccessful()) {
            $this->error('The gzip is damaged or truncated: '.trim($gzip->getErrorOutput()));

            return self::FAILURE;
        }

        $this->line('  gzip integrity: ok');

        $handle = gzopen($backup, 'rb');

        if ($handle === false) {
            $this->error('The backup will not open.');

            return self::FAILURE;
        }

        $tables = [];
        $tail = '';

        while (! gzeof($handle)) {
            $chunk = gzread($handle, 262144);

            if ($chunk === false) {
                gzclose($handle);
                $this->error('The backup stopped being readable part way through.');

                return self::FAILURE;
            }

            $tail = substr($tail.$chunk, -1024);

            if (preg_match_all('/CREATE TABLE `([^`]+)`/', $chunk, $matches)) {
                $tables = array_merge($tables, $matches[1]);
            }
        }

        gzclose($handle);

        if (! str_contains($tail, 'Dump completed')) {
            $this->error('The dump has no completion marker, so it stopped early. Do not rely on it.');

            return self::FAILURE;
        }

        $this->line('  completion marker: present');

        $missing = array_diff($this->liveTables(), $tables);

        if ($missing !== []) {
            $this->error('The dump is missing tables the database has: '.implode(', ', $missing));

            return self::FAILURE;
        }

        $this->line('  tables: all '.count($this->liveTables()).' present');

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function liveTables(): array
    {
        return collect(DB::connection($this->option('connection') ?: null)->getSchemaBuilder()->getTableListing(schemaQualified: false))
            ->reject(fn (string $table): bool => in_array($table, self::VOLATILE, true))
            ->values()
            ->all();
    }

    /** @param  callable(string): int  $countInBackup */
    private function compareTables(callable $countInBackup): int
    {
        $live = DB::connection($this->option('connection') ?: null);
        $problems = [];

        foreach ($this->liveTables() as $table) {
            $liveRows = $live->table($table)->count();

            try {
                $restored = $countInBackup($table);
            } catch (\Throwable) {
                $problems[] = "{$table}: not in the backup at all";

                continue;
            }

            if ($liveRows > 0 && $restored === 0) {
                $problems[] = "{$table}: {$liveRows} rows live, none in the backup";
            }
        }

        foreach ($problems as $problem) {
            $this->error('  '.$problem);
        }

        if ($problems !== []) {
            return self::FAILURE;
        }

        $this->info('The backup opens, passes its own integrity check, and carries every table.');

        return self::SUCCESS;
    }
}
