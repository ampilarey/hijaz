<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * Take a database backup before anything is allowed to change it.
 *
 * The deploy runs `migrate --force` unattended on the live database, so a
 * copy is taken first, every time. This command exits non-zero if it cannot
 * produce a file it has checked, and the deploy script stops when it does.
 */
class BackupDatabase extends Command
{
    protected $signature = 'hijaz:backup
                            {--path= : Directory to write into (default storage/app/backups)}
                            {--keep=10 : How many previous backups to retain}
                            {--connection= : Which database connection to dump (default: the app default)}';

    protected $description = 'Dump the database to a timestamped, gzipped file';

    public function handle(): int
    {
        $connection = $this->option('connection') ?: config('database.default');
        $config = config("database.connections.{$connection}");

        if (DB::connection($connection)->transactionLevel() > 0) {
            $this->error('A transaction is open on this connection. Refusing to take a backup inside it.');

            return self::FAILURE;
        }

        $directory = $this->option('path') ?: storage_path('app/backups');
        File::ensureDirectoryExists($directory, 0700);

        $stamp = now()->format('Y-m-d_His_v');

        $target = match ($config['driver']) {
            'mysql', 'mariadb' => $this->dumpMysql($config, "{$directory}/hijaz-{$stamp}.sql.gz"),
            'sqlite' => $this->copySqlite($connection, "{$directory}/hijaz-{$stamp}.sqlite"),
            default => null,
        };

        if ($target === null) {
            $this->error("No backup strategy for driver [{$config['driver']}]. Refusing to continue.");

            return self::FAILURE;
        }

        if (! File::exists($target) || File::size($target) < 1024) {
            $this->error('The backup is missing or implausibly small. Refusing to report success.');

            return self::FAILURE;
        }

        $this->info(sprintf('Backup written: %s (%s KB)', $target, number_format(File::size($target) / 1024)));

        $this->prune($directory);

        return self::SUCCESS;
    }

    /** @param  array<string, mixed>  $config */
    private function dumpMysql(array $config, string $target): ?string
    {
        // Credentials go in the environment, never on the command line.
        $process = Process::fromShellCommandline(
            'mysqldump --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USER" '
            .'--single-transaction --quick --routines --events '
            .'--no-tablespaces "$DB_NAME" | (umask 077; gzip > "$DB_TARGET")',
            null,
            [
                'MYSQL_PWD' => (string) $config['password'],
                'DB_HOST' => (string) $config['host'],
                'DB_PORT' => (string) $config['port'],
                'DB_USER' => (string) $config['username'],
                'DB_NAME' => (string) $config['database'],
                'DB_TARGET' => $target,
            ],
            null,
            600,
        );

        $process->run();

        if (! $process->isSuccessful()) {
            $this->error('mysqldump failed: '.trim($process->getErrorOutput()));

            return null;
        }

        return $target;
    }

    private function copySqlite(string $connection, string $target): ?string
    {
        try {
            DB::connection($connection)->statement('VACUUM INTO ?', [$target]);
        } catch (\Throwable $e) {
            $this->error('SQLite backup failed: '.$e->getMessage());

            return null;
        }

        return $target;
    }

    private function prune(string $directory): void
    {
        $keep = max(1, (int) $this->option('keep'));

        collect(File::files($directory))
            ->filter(fn ($file) => str_starts_with($file->getFilename(), 'hijaz-'))
            ->sortByDesc(fn ($file) => $file->getMTime())
            ->values()
            ->slice($keep)
            ->each(function ($file): void {
                File::delete($file->getPathname());
                $this->line('  pruned '.$file->getFilename());
            });
    }
}
