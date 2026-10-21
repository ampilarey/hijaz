<?php

namespace Database\Seeders;

use App\Models\LedgerCategory;
use Illuminate\Database\Seeder;

/** The categories the books are kept in. Upserted by slug, so renames in the portal stick and new ones arrive on deploy. */
class LedgerCategoriesSeeder extends Seeder
{
    public function run(): void
    {
        $n = 0;

        foreach (LedgerCategory::DEFAULTS as $kind => $names) {
            foreach ($names as $slug => $name) {
                LedgerCategory::query()->firstOrCreate(['slug' => $slug], ['kind' => $kind, 'name' => $name, 'sort_order' => ++$n]);
            }
        }
    }
}
