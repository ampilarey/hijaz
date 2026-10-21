<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How much of a payment settles which currency balance, with the rate used
 * when the currencies differ. A payment in its own currency has one
 * allocation with no rate.
 *
 * @property int $payment_id
 * @property string $currency
 * @property int $amount_minor
 * @property string|null $rate_snapshot
 * @property int|null $exchange_rate_id
 */
class PaymentAllocation extends Model
{
    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'rate_snapshot' => 'decimal:8'];
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function money(): Money
    {
        return Money::ofMinor($this->amount_minor, $this->currency);
    }
}
