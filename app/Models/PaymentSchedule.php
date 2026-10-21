<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An instalment: this much, in this currency, by this date.
 *
 * @property int $booking_id
 * @property string $label
 * @property string $currency
 * @property int $amount_minor
 * @property Carbon $due_on
 */
class PaymentSchedule extends Model
{
    /** @var list<string> */
    protected $fillable = ['booking_id', 'label', 'currency', 'amount_minor', 'due_on', 'sort_order'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['due_on' => 'date', 'amount_minor' => 'integer'];
    }

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function money(): Money
    {
        return Money::ofMinor($this->amount_minor, $this->currency);
    }

    /** Due and not covered by what has been paid in its currency so far (earlier instalments first). */
    public function isOverdue(): bool
    {
        if ($this->due_on->isFuture()) {
            return false;
        }

        $booking = $this->booking;
        $dueSoFar = (int) $booking->schedule()->where('currency', $this->currency)->where('due_on', '<=', $this->due_on)->sum('amount_minor');

        return $booking->paidIn($this->currency)->minor < $dueSoFar;
    }
}
