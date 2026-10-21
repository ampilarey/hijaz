<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * One movement of money in or out of one account. Appended, never edited;
 * voided with a reason when wrong, and still there.
 *
 * @property int $account_id
 * @property string $direction
 * @property string $kind
 * @property int|null $category_id
 * @property int|null $trip_id
 * @property Carbon $occurred_on
 * @property string $currency
 * @property int $amount_minor
 * @property int|null $mvr_minor
 * @property string|null $rate_snapshot
 * @property string $description
 * @property string|null $counterparty
 * @property string|null $reference
 * @property string|null $group_key
 * @property int|null $payment_id
 * @property array<string, mixed>|null $meta
 * @property Carbon|null $voided_at
 * @property string|null $void_reason
 */
class LedgerEntry extends Model
{
    public const IN = 'in';

    public const OUT = 'out';

    public const INCOME = 'income';

    public const EXPENSE = 'expense';

    public const TRANSFER = 'transfer';

    public const EXCHANGE = 'exchange';

    /** @var array<string, string> */
    public const KINDS = [self::INCOME => 'Income', self::EXPENSE => 'Expense', self::TRANSFER => 'Transfer', self::EXCHANGE => 'Currency purchase'];

    /** @var list<string> */
    protected $fillable = ['account_id', 'direction', 'kind', 'category_id', 'trip_id', 'occurred_on', 'currency', 'amount_minor', 'mvr_minor', 'rate_snapshot', 'description', 'counterparty', 'reference', 'group_key', 'payment_id', 'meta', 'recorded_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['occurred_on' => 'date', 'amount_minor' => 'integer', 'mvr_minor' => 'integer', 'rate_snapshot' => 'decimal:8', 'meta' => 'array', 'voided_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn (LedgerEntry $e) => $e->recorded_by ??= Auth::id());
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return BelongsTo<LedgerCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(LedgerCategory::class, 'category_id');
    }

    /** @return BelongsTo<Trip, $this> */
    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** @return MorphMany<Document, $this> */
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function evidence(): ?Document
    {
        return $this->documents->firstWhere('type', Document::EVIDENCE);
    }

    public function money(): Money
    {
        return Money::ofMinor($this->amount_minor, $this->currency);
    }

    /** Positive in, negative out: what it does to the account. */
    public function signedMinor(): int
    {
        return $this->direction === self::IN ? $this->amount_minor : -$this->amount_minor;
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }

    /** @param  Builder<LedgerEntry>  $query */
    public function scopeLive(Builder $query): void
    {
        $query->whereNull('voided_at');
    }

    /** @param  Builder<LedgerEntry>  $query */
    public function scopeBetween(Builder $query, ?Carbon $from, ?Carbon $to): void
    {
        $query->when($from, fn ($q) => $q->whereDate('occurred_on', '>=', $from))->when($to, fn ($q) => $q->whereDate('occurred_on', '<=', $to));
    }
}
