<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * Typed access to the handful of values staff may change without a deploy.
 * Defaults come from config/hijaz.php so a fresh database is already right.
 */
final class Settings
{
    public const COMPANY_NAME = 'company.name';

    public const COMPANY_ADDRESS = 'company.address';

    public const COMPANY_EMAIL = 'company.email';

    public const COMPANY_PHONES = 'company.phones';

    public const COMPANY_REGISTRATION = 'company.registration';

    public const PASSPORT_VALIDITY_MONTHS = 'documents.passport_validity_months';

    private const CACHE_KEY = 'settings.all';

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            self::COMPANY_NAME => config('hijaz.name'),
            self::COMPANY_ADDRESS => '',
            self::COMPANY_EMAIL => config('hijaz.email'),
            self::COMPANY_PHONES => implode(' / ', array_column((array) config('hijaz.phones'), 'display')),
            self::COMPANY_REGISTRATION => '',
            self::PASSPORT_VALIDITY_MONTHS => 6,
        ];
    }

    public static function get(string $key): mixed
    {
        return self::all()[$key] ?? self::defaults()[$key] ?? null;
    }

    public static function set(string $key, mixed $value): void
    {
        Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget(self::CACHE_KEY);
    }

    /** @return array<string, mixed> */
    public static function all(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function (): array {
            $stored = Setting::query()->pluck('value', 'key')->all();

            return array_merge(self::defaults(), $stored);
        });
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
