<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Island;
use App\Services\Pilgrims\Duplicates;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Bring in the pilgrims Hijaz already has in a spreadsheet, saved as CSV.
 *
 * Columns (header row, any order, case-insensitive): name, id_number,
 * date_of_birth, gender, phone, address, island, passport_number,
 * passport_expires_on, family. Rows sharing a `family` value become one
 * family; the first row is its head. A row whose ID number is already on
 * file is reported and skipped, never merged.
 */
class ImportPilgrims extends Command
{
    protected $signature = 'pilgrims:import {file : Path to the CSV} {--dry-run : Report what would happen and write nothing}';

    protected $description = 'Import pilgrims from a CSV export of the old spreadsheet';

    public function handle(): int
    {
        $path = (string) $this->argument('file');

        if (! is_readable($path)) {
            $this->error("Cannot read {$path}");

            return self::FAILURE;
        }

        $handle = fopen($path, 'r');
        $header = array_map(fn ($h) => strtolower(trim((string) $h)), fgetcsv($handle) ?: []);
        $rows = [];

        while (($row = fgetcsv($handle)) !== false) {
            if (count(array_filter($row, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }

            $rows[] = array_combine($header, array_pad(array_map('trim', $row), count($header), null)) ?: [];
        }

        fclose($handle);

        $new = 0;
        $skipped = 0;
        $families = [];
        $dry = (bool) $this->option('dry-run');

        DB::beginTransaction();

        foreach ($rows as $i => $row) {
            $name = strtoupper((string) ($row['name'] ?? ''));

            if ($name === '') {
                $this->line('row '.($i + 2).': no name, skipped');
                $skipped++;

                continue;
            }

            $existing = Duplicates::find($row['id_number'] ?? null, $row['passport_number'] ?? null, $name, $this->date($row['date_of_birth'] ?? null));

            if ($existing->isNotEmpty()) {
                $this->line('row '.($i + 2).": {$name} already on file as #{$existing->first()->id}, skipped");
                $skipped++;

                continue;
            }

            $familyKey = trim((string) ($row['family'] ?? ''));

            if ($familyKey !== '' && isset($families[$familyKey])) {
                $customer = $families[$familyKey];
                $relationship = 'relative';
            } else {
                $customer = Customer::query()->create([
                    'type' => $familyKey !== '' ? Customer::FAMILY : Customer::INDIVIDUAL,
                    'name' => $familyKey !== '' ? $familyKey : $name,
                    'phone' => $row['phone'] ?? null,
                    'address' => $row['address'] ?? null,
                    'source' => 'import',
                ]);
                $relationship = 'self';

                if ($familyKey !== '') {
                    $families[$familyKey] = $customer;
                }
            }

            $island = filled($row['island'] ?? null) ? Island::query()->where('name', $row['island'])->first() : null;

            $pilgrim = $customer->pilgrims()->create([
                'relationship_to_head' => $relationship,
                'name_en' => $name,
                'gender' => in_array(strtolower((string) ($row['gender'] ?? '')), ['m', 'male']) ? 'male' : (in_array(strtolower((string) ($row['gender'] ?? '')), ['f', 'female']) ? 'female' : null),
                'date_of_birth' => $this->date($row['date_of_birth'] ?? null),
                'id_number' => $row['id_number'] ?? null,
                'address' => $row['address'] ?? null,
                'island_id' => $island?->id,
                'passport_number' => $row['passport_number'] ?? null,
                'passport_expires_on' => $this->date($row['passport_expires_on'] ?? null),
                'phone' => $row['phone'] ?? null,
            ]);

            if ($relationship === 'self') {
                $customer->forceFill(['head_pilgrim_id' => $pilgrim->id])->save();
            }

            $new++;
        }

        if ($dry) {
            DB::rollBack();
            $this->info("Dry run: {$new} would be created, {$skipped} skipped. Nothing written.");
        } else {
            DB::commit();
            $this->info("{$new} created, {$skipped} skipped.");
        }

        return self::SUCCESS;
    }

    private function date(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        // Day first, as Maldivians write it: 01/02/1970 is the first of February.
        foreach (['d/m/Y', 'd-m-Y', 'd.m.Y', 'Y-m-d'] as $format) {
            try {
                $parsed = Carbon::createFromFormat($format, trim($value));

                if ($parsed !== false && $parsed->format($format) === trim($value)) {
                    return $parsed->toDateString();
                }
            } catch (\Throwable) {
            }
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
