<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An additional trip inside the trip: Makkah Ziyarah (included), Taif,
 * Jeddah. Priced in SAR and paid in Saudi Arabia.
 *
 * @property int $trip_id
 * @property string $title
 * @property string|null $city
 * @property Carbon|null $scheduled_on
 * @property bool $is_included
 * @property int $price_minor
 * @property string $currency
 * @property int|null $capacity
 * @property string|null $provider
 * @property int|null $cost_minor
 * @property string|null $cost_currency
 */
class TripExcursion extends Model
{
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['trip_id', 'title', 'city', 'scheduled_on', 'is_included', 'price_minor', 'currency', 'capacity', 'provider', 'cost_minor', 'cost_currency', 'notes', 'sort_order'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['scheduled_on' => 'date', 'is_included' => 'boolean', 'price_minor' => 'integer', 'cost_minor' => 'integer', 'capacity' => 'integer'];
    }

    /** @return BelongsTo<Trip, $this> */
    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function price(): Money
    {
        return Money::ofMinor($this->price_minor, $this->currency);
    }

    public function priceLabel(): string
    {
        return $this->is_included ? 'Included' : $this->price()->format().' per person';
    }
}
