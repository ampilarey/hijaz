<?php

namespace App\Models;

use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;

/**
 * The paying party: one person, or a family booking and paying together.
 *
 * @property int $id
 * @property string $type
 * @property string $name
 * @property int|null $head_pilgrim_id
 * @property string|null $phone
 * @property string|null $phone_alt
 * @property string|null $email
 * @property string|null $address
 * @property int|null $island_id
 * @property string|null $source
 */
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory;

    public const INDIVIDUAL = 'individual';

    public const FAMILY = 'family';

    /** @var array<string, string> */
    public const TYPES = [self::INDIVIDUAL => 'Individual', self::FAMILY => 'Family'];

    /** @var array<string, string> */
    public const SOURCES = [
        'walk_in' => 'Walked in',
        'phone' => 'Phoned',
        'returning' => 'Travelled before',
        'referral' => 'Referred by a pilgrim',
        'facebook' => 'Facebook',
        'viber' => 'Viber group',
        'other' => 'Other',
    ];

    /** @var list<string> */
    protected $hidden = ['portal_password'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['portal_password_set_at' => 'datetime', 'portal_last_login_at' => 'datetime'];
    }

    /** @var list<string> */
    protected $fillable = ['type', 'name', 'head_pilgrim_id', 'contact_pilgrim_id', 'phone', 'phone_alt', 'email', 'address', 'island_id', 'source', 'notes', 'created_by'];

    /** @var array<string, mixed> */
    protected $attributes = ['type' => self::INDIVIDUAL];

    protected static function booted(): void
    {
        static::creating(fn (Customer $c) => $c->created_by ??= Auth::id());
    }

    /** @return HasMany<Pilgrim, $this> */
    public function pilgrims(): HasMany
    {
        return $this->hasMany(Pilgrim::class)->orderByRaw("case when relationship_to_head = 'self' then 0 else 1 end")->orderBy('date_of_birth');
    }

    /** @return HasMany<Booking, $this> */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class)->orderByDesc('id');
    }

    /** @return BelongsTo<Pilgrim, $this> */
    public function head(): BelongsTo
    {
        return $this->belongsTo(Pilgrim::class, 'head_pilgrim_id');
    }

    /** @return BelongsTo<Pilgrim, $this> The member who leads the family and deals with the office. */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Pilgrim::class, 'contact_pilgrim_id');
    }

    /** The leader and contact: the one named, else the head of family. */
    public function contactPerson(): ?Pilgrim
    {
        return $this->contact ?? $this->head;
    }

    /** Who to address: the contact person's name, else the family's. */
    public function contactName(): string
    {
        return $this->contactPerson()?->name_en ?? $this->name;
    }

    /** The number to message: the contact person's own mobile, else the family's phone. */
    public function contactPhone(): ?string
    {
        return filled($this->contactPerson()?->phone) ? $this->contactPerson()->phone : $this->phone;
    }

    /** @return BelongsTo<Island, $this> */
    public function island(): BelongsTo
    {
        return $this->belongsTo(Island::class);
    }

    public function isFamily(): bool
    {
        return $this->type === self::FAMILY;
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }
}
