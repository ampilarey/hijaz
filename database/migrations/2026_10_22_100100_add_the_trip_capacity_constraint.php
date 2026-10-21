<?php

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A trip may never hold and confirm more seats than it has.
 *
 * The booking code takes a row lock before touching the counters; this is
 * the backstop underneath it, so a console command or an import that forgets
 * the lock cannot leave more people booked than can fly. A trip with
 * capacity 0 refuses every seat, on purpose: nobody has said how many there
 * are.
 *
 * MySQL enforces CHECK from 8.0.16 and MariaDB from 10.2.1; older versions
 * parse and ignore it, so the constraint is added only where it works. On
 * SQLite the same rule is a pair of triggers, so the tests exercise it.
 */
return new class extends Migration
{
    private const CONSTRAINT = 'trips_capacity_not_exceeded';

    private const RULE = 'capacity_held + capacity_confirmed <= capacity_total';

    public function up(): void
    {
        $connection = Schema::getConnection();

        DB::table('trips')
            ->whereRaw('capacity_held + capacity_confirmed > capacity_total')
            ->update(['capacity_total' => DB::raw('capacity_held + capacity_confirmed')]);

        match ($connection->getDriverName()) {
            'mysql', 'mariadb' => $this->addCheck($connection, $this->checksAreEnforced($connection)),
            'pgsql' => $this->addCheck($connection, true),
            'sqlite' => $this->addTriggers($connection),
            default => throw new RuntimeException('No capacity constraint is defined for the "'.$connection->getDriverName().'" driver.'),
        };
    }

    public function down(): void
    {
        $connection = Schema::getConnection();

        match ($connection->getDriverName()) {
            'sqlite' => $this->dropTriggers($connection),
            'mysql', 'mariadb', 'pgsql' => $this->dropCheck($connection),
            default => null,
        };
    }

    private function addCheck(Connection $connection, bool $enforced): void
    {
        if (! $enforced) {
            return;
        }

        $connection->statement(sprintf('ALTER TABLE trips ADD CONSTRAINT %s CHECK (%s)', self::CONSTRAINT, self::RULE));
    }

    private function dropCheck(Connection $connection): void
    {
        try {
            $connection->statement('ALTER TABLE trips DROP CONSTRAINT '.self::CONSTRAINT);
        } catch (Throwable) {
            // Never added on an older MySQL; rolling back must not fail for that.
        }
    }

    private function checksAreEnforced(Connection $connection): bool
    {
        /** @var object{version: string}|null $row */
        $row = $connection->selectOne('select version() as version');
        $version = $row->version ?? '';
        preg_match('/^\d+(\.\d+)*/', $version, $matches);
        $numeric = $matches[0] ?? '0';

        if (stripos($version, 'mariadb') !== false) {
            return version_compare($numeric, '10.2.1', '>=');
        }

        return version_compare($numeric, '8.0.16', '>=');
    }

    private function addTriggers(Connection $connection): void
    {
        foreach (['insert', 'update'] as $event) {
            $connection->statement(sprintf(
                'CREATE TRIGGER %s_%s BEFORE %s ON trips FOR EACH ROW '
                .'WHEN NEW.capacity_held + NEW.capacity_confirmed > NEW.capacity_total '
                ."BEGIN SELECT RAISE(ABORT, '%s'); END",
                self::CONSTRAINT, $event, strtoupper($event), self::CONSTRAINT,
            ));
        }
    }

    private function dropTriggers(Connection $connection): void
    {
        foreach (['insert', 'update'] as $event) {
            $connection->statement(sprintf('DROP TRIGGER IF EXISTS %s_%s', self::CONSTRAINT, $event));
        }
    }
};
