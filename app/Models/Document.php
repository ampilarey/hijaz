<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * One kind of paper for one pilgrim (or, later, a slip for a payment).
 * Versions keep every upload; the document carries the status.
 *
 * @property int $id
 * @property string $documentable_type
 * @property int $documentable_id
 * @property string $type
 * @property int|null $trip_id
 * @property string $status
 * @property string|null $number
 * @property Carbon|null $issued_at
 * @property Carbon|null $expires_at
 * @property int|null $verified_by
 * @property Carbon|null $verified_at
 * @property string|null $rejection_reason
 */
class Document extends Model
{
    use HasFactory;

    public const PENDING = 'pending';

    public const VERIFIED = 'verified';

    public const REJECTED = 'rejected';

    public const ID_CARD = 'id_card';

    public const PASSPORT = 'passport';

    public const PHOTO = 'photo';

    public const VISA = 'visa';

    public const VACCINATION = 'vaccination';

    public const BANK_SLIP = 'bank_slip';

    /** A receipt, a TT advice, an exchange slip: what a ledger entry rests on. */
    public const EVIDENCE = 'evidence';

    public const OTHER = 'other';

    /** @var array<string, string> */
    public const TYPES = [
        self::ID_CARD => 'ID card',
        self::PASSPORT => 'Passport',
        self::PHOTO => 'Passport photo',
        self::VISA => 'Visa',
        self::VACCINATION => 'Vaccination certificate',
        self::EVIDENCE => 'Evidence (receipt, advice, slip)',
        self::OTHER => 'Other',
    ];

    /** The copies every pilgrim is expected to have, in the order they usually arrive. */
    public const REQUIRED_FOR_PILGRIMS = [self::ID_CARD, self::PASSPORT, self::PHOTO];

    /** Types the reader knows how to propose fields for. */
    public const READABLE = [self::ID_CARD, self::PASSPORT, self::VISA, self::BANK_SLIP];

    /** @var list<string> */
    protected $fillable = ['type', 'trip_id', 'number', 'issued_at', 'expires_at', 'notes', 'created_by'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => self::PENDING];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['issued_at' => 'date', 'expires_at' => 'date', 'verified_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn (Document $d) => $d->created_by ??= Auth::id());
    }

    /** @return MorphTo<Model, $this> */
    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<Trip, $this> */
    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    /** @return BelongsTo<User, $this> */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /** @return HasMany<DocumentVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class)->orderByDesc('version');
    }

    public function currentVersion(): ?DocumentVersion
    {
        return $this->versions->firstWhere('superseded_at', null) ?? $this->versions->first();
    }

    public function latestExtraction(): ?DocumentExtraction
    {
        return $this->currentVersion()?->extractions()->latest('id')->first();
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? ucfirst(str_replace('_', ' ', $this->type));
    }

    public function isVerified(): bool
    {
        return $this->status === self::VERIFIED;
    }

    public function isReadable(): bool
    {
        return in_array($this->type, self::READABLE, true);
    }

    /** @param  Builder<Document>  $query */
    public function scopePending(Builder $query): void
    {
        $query->where('status', self::PENDING);
    }

    /** @param  Builder<Document>  $query */
    public function scopeForPilgrims(Builder $query): void
    {
        $query->where('documentable_type', Pilgrim::class);
    }
}
