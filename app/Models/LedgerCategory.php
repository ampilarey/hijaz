<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $kind
 * @property string $slug
 * @property string $name
 * @property bool $is_active
 */
class LedgerCategory extends Model
{
    public const INCOME = 'income';

    public const EXPENSE = 'expense';

    /** @var array<string, array<string, string>> kind => slug => name */
    public const DEFAULTS = [
        self::INCOME => [
            'package_sales' => 'Package sales',
            'room_upgrades' => 'Room upgrades',
            'excursion_sales' => 'Additional trip sales',
            'fees_income' => 'Fees and extras charged',
            'other_income' => 'Other income',
        ],
        self::EXPENSE => [
            'airfare' => 'Airfare',
            'makkah_hotel' => 'Makkah hotel',
            'madinah_hotel' => 'Madinah hotel',
            'transport' => 'Transport',
            'excursion_costs' => 'Additional trip costs',
            'visa_fees' => 'Visa fees',
            'nusuk' => 'Nusuk',
            'meals' => 'Meals',
            'staff' => 'Staff',
            'refunds' => 'Refunds to pilgrims',
            'bank_charges' => 'Bank charges and TT fees',
            'exchange_loss' => 'Exchange loss',
            'office' => 'Office',
            'other_expense' => 'Other expense',
        ],
    ];

    /** @var list<string> */
    protected $fillable = ['kind', 'slug', 'name', 'sort_order', 'is_active'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return HasMany<LedgerEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class, 'category_id');
    }

    public static function bySlug(string $slug): ?self
    {
        return static::query()->where('slug', $slug)->first();
    }

    /** @return array<int, string> */
    public static function options(?string $kind = null): array
    {
        return static::query()->where('is_active', true)->when($kind, fn ($q) => $q->where('kind', $kind))->orderBy('sort_order')->pluck('name', 'id')->all();
    }
}
