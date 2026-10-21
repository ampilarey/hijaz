<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * What the trip costs to run. Entered while the trip is selling as an
 * estimate, and the same rows become the actual figures as invoices arrive.
 *
 * @property int $trip_id
 * @property string $category
 * @property string|null $supplier
 * @property string|null $description
 * @property string $currency
 * @property int $amount_minor
 * @property bool $is_per_person
 * @property string $status
 */
class TripCost extends Model
{
    use HasFactory;

    /** @var array<string, string> */
    public const CATEGORIES = [
        'airfare' => 'Airfare',
        'makkah_hotel' => 'Makkah hotel',
        'madinah_hotel' => 'Madinah hotel',
        'transport' => 'Transport',
        'excursion' => 'Excursions',
        'visa' => 'Visa fees',
        'nusuk' => 'Nusuk',
        'meals' => 'Meals',
        'staff' => 'Staff',
        'bank' => 'Bank charges',
        'other' => 'Other',
    ];

    public const ESTIMATED = 'estimated';

    public const COMMITTED = 'committed';

    public const PAID = 'paid';

    /** @var array<string, string> */
    public const STATUSES = [
        self::ESTIMATED => 'Estimated',
        self::COMMITTED => 'Agreed',
        self::PAID => 'Paid',
    ];

    /** @var list<string> */
    protected $fillable = ['trip_id', 'category', 'supplier', 'description', 'currency', 'amount_minor', 'is_per_person', 'status', 'incurred_on', 'created_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'is_per_person' => 'boolean', 'incurred_on' => 'date'];
    }

    protected static function booted(): void
    {
        static::creating(fn (TripCost $cost) => $cost->created_by ??= Auth::id());
    }

    /** @return BelongsTo<Trip, $this> */
    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function unit(): Money
    {
        return Money::ofMinor($this->amount_minor, $this->currency);
    }

    public function totalFor(int $travellers): Money
    {
        return Money::ofMinor($this->is_per_person ? $this->amount_minor * $travellers : $this->amount_minor, $this->currency);
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? ucfirst($this->category);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst($this->status);
    }
}
