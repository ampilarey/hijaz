<?php

namespace App\Models;

use App\Support\Currency;
use App\Support\Money;
use Database\Factories\ExchangeRateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $from_currency
 * @property string $to_currency
 * @property string $rate
 * @property Carbon $as_of
 * @property string|null $source
 * @property int|null $set_by
 */
class ExchangeRate extends Model
{
    /** @use HasFactory<ExchangeRateFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['from_currency', 'to_currency', 'rate', 'as_of', 'source', 'set_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['as_of' => 'date', 'rate' => 'decimal:8'];
    }

    /** @return BelongsTo<User, $this> */
    public function setter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by');
    }

    /**
     * The rate in force on a date: the latest one dated on or before it.
     * Null means nobody has set one, and the caller says so rather than
     * inventing a number.
     */
    public static function inForce(string $from, string $to, ?Carbon $on = null): ?self
    {
        if ($from === $to) {
            return null;
        }

        return static::query()
            ->where('from_currency', $from)
            ->where('to_currency', $to)
            ->whereDate('as_of', '<=', ($on ?? now())->toDateString())
            ->orderByDesc('as_of')
            ->first();
    }

    /** Convert, rounding to the nearest minor unit. The caller keeps this rate on the record. */
    public function convert(Money $money): Money
    {
        if ($money->currency !== $this->from_currency) {
            throw new \InvalidArgumentException("This rate converts {$this->from_currency}, not {$money->currency}.");
        }

        return Money::ofMinor((int) round($money->minor * (float) $this->rate), $this->to_currency);
    }

    public function getLabelAttribute(): string
    {
        return "1 {$this->from_currency} = ".rtrim(rtrim($this->rate, '0'), '.')." {$this->to_currency}";
    }

    /** @return list<array{string, string}> */
    public static function pairs(): array
    {
        $pairs = [];

        foreach (Currency::ALL as $from) {
            foreach (Currency::ALL as $to) {
                if ($from !== $to) {
                    $pairs[] = [$from, $to];
                }
            }
        }

        return $pairs;
    }
}
