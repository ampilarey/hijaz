<?php

namespace App\Models;

use App\Exceptions\IllegalPaymentTransition;
use App\Support\AuditContext;
use App\Support\Money;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Money that arrived (or, negative, left). Appended, never edited: a
 * terminal payment is reversed by a refund. Status moves only through
 * transitionTo(), which writes a transaction row.
 *
 * @property int $id
 * @property string $reference
 * @property string $payable_type
 * @property int $payable_id
 * @property int|null $refund_of_id
 * @property string $method
 * @property int|null $account_id
 * @property string $currency
 * @property int $amount_minor
 * @property string $status
 * @property Carbon|null $paid_on
 * @property string|null $payer_name
 * @property string|null $payer_bank
 * @property string|null $payer_reference
 * @property string $channel
 * @property int|null $recorded_by
 * @property int|null $reviewed_by
 * @property string|null $rejection_reason
 */
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    public const BANK_TRANSFER = 'bank_transfer';

    public const CASH = 'cash';

    /** @var array<string, string> */
    public const METHODS = [self::BANK_TRANSFER => 'Bank transfer', self::CASH => 'Cash'];

    public const PENDING = 'pending';

    public const AWAITING_REVIEW = 'awaiting_review';

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    /** @var array<string, string> */
    public const STATUSES = [
        self::PENDING => 'Recorded',
        self::AWAITING_REVIEW => 'Waiting for Finance',
        self::SUCCEEDED => 'Verified',
        self::FAILED => 'Not received',
        self::CANCELLED => 'Cancelled',
    ];

    /** @var array<string, list<string>> */
    public const TRANSITIONS = [
        self::PENDING => [self::AWAITING_REVIEW, self::SUCCEEDED, self::FAILED, self::CANCELLED],
        self::AWAITING_REVIEW => [self::SUCCEEDED, self::FAILED, self::CANCELLED],
        self::SUCCEEDED => [],
        self::FAILED => [],
        self::CANCELLED => [],
    ];

    /** @var list<string> */
    protected $fillable = ['payable_type', 'payable_id', 'refund_of_id', 'method', 'account_id', 'currency', 'amount_minor', 'paid_on', 'payer_name', 'payer_bank', 'payer_reference', 'notes'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => self::PENDING, 'channel' => 'portal'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['paid_on' => 'date', 'reviewed_at' => 'datetime', 'amount_minor' => 'integer'];
    }

    protected static function booted(): void
    {
        static::creating(function (Payment $p): void {
            $p->recorded_by ??= Auth::id();
            $p->channel = AuditContext::channel();
            $p->reference = $p->reference ?: 'PENDING';
        });

        static::created(function (Payment $p): void {
            if ($p->reference === 'PENDING') {
                $p->forceFill(['reference' => sprintf('%s-P-%s-%04d', config('booking.reference_prefix', 'HJZ'), now()->format('Y'), $p->getKey())])->saveQuietly();
            }
        });
    }

    /** @return MorphTo<Model, $this> */
    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return BelongsTo<Payment, $this> */
    public function refundOf(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'refund_of_id');
    }

    /** @return HasMany<Payment, $this> */
    public function refunds(): HasMany
    {
        return $this->hasMany(Payment::class, 'refund_of_id');
    }

    /** @return HasMany<PaymentTransaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class)->orderBy('id');
    }

    /** @return HasMany<PaymentAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    /** @return MorphMany<Document, $this> */
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function slip(): ?Document
    {
        return $this->documents->firstWhere('type', Document::BANK_SLIP);
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function money(): Money
    {
        return Money::ofMinor($this->amount_minor, $this->currency);
    }

    public function isRefund(): bool
    {
        return $this->refund_of_id !== null || $this->amount_minor < 0;
    }

    public function isTerminal(): bool
    {
        return self::TRANSITIONS[$this->status] === [];
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function transitionTo(string $status, ?string $reason = null, ?User $actor = null): void
    {
        if (! in_array($status, self::TRANSITIONS[$this->status] ?? [], true)) {
            throw IllegalPaymentTransition::from($this->status, $status);
        }

        $from = $this->status;
        $actorId = ($actor ?? Auth::user())?->getKey();

        $this->forceFill(array_merge(['status' => $status], in_array($status, [self::SUCCEEDED, self::FAILED], true) ? [
            'reviewed_by' => $actorId,
            'reviewed_at' => now(),
            'rejection_reason' => $status === self::FAILED ? $reason : null,
        ] : []))->save();

        $this->transactions()->create([
            'type' => PaymentTransaction::REVIEWED,
            'from_status' => $from,
            'to_status' => $status,
            'user_id' => $actorId,
            'reason' => $reason,
        ]);
    }

    /** @param  Builder<Payment>  $query */
    public function scopeSucceeded(Builder $query): void
    {
        $query->where('status', self::SUCCEEDED);
    }

    /** @param  Builder<Payment>  $query */
    public function scopeAwaitingReview(Builder $query): void
    {
        $query->whereIn('status', [self::PENDING, self::AWAITING_REVIEW]);
    }
}
