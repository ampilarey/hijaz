<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Everything here is reference data the portal needs to run, never demo
 * content, so it is safe on production and runs on every deploy.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            IslandsSeeder::class,
            AccountsSeeder::class,
            LedgerCategoriesSeeder::class,
        ]);
    }
}
