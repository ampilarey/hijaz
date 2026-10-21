<?php

namespace Database\Seeders;

use App\Models\Island;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

/**
 * The inhabited islands by atoll, from database/data/islands.json. Adds
 * islands that are missing and never touches rows staff have edited, so a
 * name corrected in the portal stays corrected.
 */
class IslandsSeeder extends Seeder
{
    public function run(): void
    {
        $rows = json_decode(File::get(database_path('data/islands.json')), true, 512, JSON_THROW_ON_ERROR);

        foreach ($rows as $row) {
            Island::query()->firstOrCreate(
                ['atoll_code' => $row['atoll_code'], 'name' => $row['name']],
                ['atoll_name' => $row['atoll_name'], 'is_active' => true],
            );
        }
    }
}
