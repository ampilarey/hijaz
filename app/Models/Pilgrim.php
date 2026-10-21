<?php

namespace App\Models;

use App\Casts\EncryptedIdentifier;
use App\Support\BlindIndex;
use App\Support\Settings;
use Database\Factories\PilgrimFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * One human who travels. Registered with a name and a phone; everything
 * else, including the copies, can come later.
 *
 * @property int $id
 * @property int $customer_id
 * @property string $relationship_to_head
 * @property string $name_en
 * @property string|null $name_dv
 * @property string|null $gender
 * @property Carbon|null $date_of_birth
 * @property string|null $id_number
 * @property string|null $id_number_hash
 * @property Carbon|null $id_expires_on
 * @property string|null $address
 * @property int|null $island_id
 * @property string $nationality
 * @property string|null $passport_number
 * @property string|null $passport_number_hash
 * @property Carbon|null $passport_issued_on
 * @property Carbon|null $passport_expires_on
 * @property string|null $passport_issuing_country
 * @property string|null $phone
 * @property string|null $emergency_contact_name
 * @property string|null $emergency_contact_phone
 * @property string|null $medical_notes
 * @property array<string, mixed>|null $needs
 * @property string $registration_status
 * @property array<int, array<string, mixed>>|null $identity_mismatches
 */
class Pilgrim extends Model
{
    /** @use HasFactory<PilgrimFactory> */
    use HasFactory;

    public const REGISTERED = 'registered';

    public const VERIFIED = 'verified';

    /** @var array<string, string> */
    public const RELATIONSHIPS = [
        'self' => 'Head of family / self',
        'spouse' => 'Spouse',
        'child' => 'Child',
        'parent' => 'Parent',
        'sibling' => 'Sibling',
        'relative' => 'Relative',
        'other' => 'Other',
    ];

    /** @var array<string, string> */
    public const GENDERS = ['male' => 'Male', 'female' => 'Female'];

    /** @var array<string, string> */
    public const NEEDS = [
        'wheelchair' => 'Wheelchair',
        'elderly' => 'Elderly, needs a companion',
        'medical' => 'Ongoing medical condition',
        'dietary' => 'Dietary requirement',
        'infant' => 'Travelling with an infant',
    ];

    /** @var list<string> */
    protected $fillable = [
        'customer_id', 'relationship_to_head', 'name_en', 'name_dv', 'gender', 'date_of_birth',
        'id_number', 'id_expires_on', 'address', 'island_id', 'nationality',
        'passport_number', 'passport_issued_on', 'passport_expires_on', 'passport_issuing_country',
        'phone', 'emergency_contact_name', 'emergency_contact_phone', 'medical_notes', 'needs', 'notes', 'created_by',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['registration_status' => self::REGISTERED, 'relationship_to_head' => 'self', 'nationality' => 'MDV'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'id_expires_on' => 'date',
            'passport_issued_on' => 'date',
            'passport_expires_on' => 'date',
            'verified_at' => 'datetime',
            'needs' => 'array',
            'identity_mismatches' => 'array',
            'id_number' => EncryptedIdentifier::class,
            'passport_number' => EncryptedIdentifier::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (Pilgrim $p) => $p->created_by ??= Auth::id());

        // The blind indexes follow the identifiers, whoever sets them.
        static::saving(function (Pilgrim $p): void {
            $p->id_number_hash = BlindIndex::of($p->id_number);
            $p->passport_number_hash = BlindIndex::of($p->passport_number);
        });
    }

    // ── Relations ────────────────────────────────────────────────────────

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<Island, $this> */
    public function island(): BelongsTo
    {
        return $this->belongsTo(Island::class);
    }

    /** @return BelongsTo<User, $this> */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /** @return MorphMany<Document, $this> */
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable')->orderBy('type');
    }

    public function document(string $type, ?int $tripId = null): ?Document
    {
        return $this->documents->first(fn (Document $d): bool => $d->type === $type && $d->trip_id === $tripId);
    }

    // ── Lookups ──────────────────────────────────────────────────────────

    /** @param  Builder<Pilgrim>  $query */
    public function scopeWithIdNumber(Builder $query, string $idNumber): void
    {
        $query->where('id_number_hash', BlindIndex::of($idNumber));
    }

    /** @param  Builder<Pilgrim>  $query */
    public function scopeWithPassportNumber(Builder $query, string $number): void
    {
        $query->where('passport_number_hash', BlindIndex::of($number));
    }

    public static function findByIdNumber(string $idNumber): ?self
    {
        return static::query()->withIdNumber($idNumber)->first();
    }

    // ── Derived ──────────────────────────────────────────────────────────

    public function isVerified(): bool
    {
        return $this->registration_status === self::VERIFIED;
    }

    public function ageOn(Carbon $date): ?int
    {
        return $this->date_of_birth?->diffInYears($date) === null ? null : (int) $this->date_of_birth->diffInYears($date);
    }

    public function paxTypeOn(Carbon $date): string
    {
        $age = $this->ageOn($date);

        return match (true) {
            $age === null => PriceTier::ADULT,
            $age < 2 => PriceTier::INFANT,
            $age < 12 => PriceTier::CHILD,
            default => PriceTier::ADULT,
        };
    }

    /** Null when no expiry is known; otherwise whether it outlasts the return by the configured months. */
    public function passportOkFor(Carbon $returnsOn, ?int $months = null): ?bool
    {
        if ($this->passport_expires_on === null) {
            return null;
        }

        $months ??= (int) Settings::get(Settings::PASSPORT_VALIDITY_MONTHS);

        return $this->passport_expires_on->greaterThanOrEqualTo($returnsOn->copy()->addMonths($months));
    }

    public function unexplainedMismatches(): array
    {
        return array_values(array_filter($this->identity_mismatches ?? [], fn (array $m): bool => empty($m['explained'])));
    }

    public function idNumberMasked(): ?string
    {
        $n = $this->id_number;

        return $n === null ? null : substr($n, 0, 1).'•••'.substr($n, -3);
    }

    public function getLabelAttribute(): string
    {
        return $this->name_en;
    }
}
