<?php

namespace App\Models;

use App\Exceptions\LastAdmin;
use App\Support\Access;
use App\Support\Phone;
use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Spatie\Permission\Traits\HasRoles;

/**
 * A member of staff. Pilgrims are not users; they are pilgrims.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $phone
 * @property bool $is_active
 * @property Carbon|null $last_login_at
 * @property string|null $app_authentication_secret
 * @property array<int, string>|null $app_authentication_recovery_codes
 */
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /** @var list<string> */
    /** One shape for every number, so the one typed at sign-in finds the account. */
    public function setPhoneAttribute(?string $value): void
    {
        $this->attributes['phone'] = Phone::normalise($value);
    }

    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'is_active',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_active' => true,
    ];

    /** @var list<string> */
    protected $hidden = [
        'password',
        'remember_token',
        'app_authentication_secret',
        'app_authentication_recovery_codes',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'is_active' => 'boolean',
            'password' => 'hashed',
            'app_authentication_secret' => 'encrypted',
            'app_authentication_recovery_codes' => 'encrypted:array',
        ];
    }

    /**
     * Being signed in is not enough: a deactivated account keeps its
     * password and roles (for the audit trail) and gets in nowhere.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active && $this->can('portal.access');
    }

    /** Admin and Finance must have an authenticator app before they use the portal. */
    public function mustUseSecondFactor(): bool
    {
        return config('portal.second_factor_required')
            && $this->hasAnyRole(Access::SECOND_FACTOR_ROLES);
    }

    public function getAppAuthenticationSecret(): ?string
    {
        return $this->app_authentication_secret;
    }

    public function saveAppAuthenticationSecret(?string $secret): void
    {
        $this->forceFill(['app_authentication_secret' => $secret])->save();
    }

    public function getAppAuthenticationHolderName(): string
    {
        return $this->email;
    }

    public function getAppAuthenticationRecoveryCodes(): ?array
    {
        return $this->app_authentication_recovery_codes;
    }

    public function saveAppAuthenticationRecoveryCodes(?array $codes): void
    {
        $this->forceFill(['app_authentication_recovery_codes' => $codes])->save();
    }

    public function hasSecondFactor(): bool
    {
        return filled($this->app_authentication_secret);
    }

    /** @return HasOne<TelegramLink, $this> */
    public function telegramLink(): HasOne
    {
        return $this->hasOne(TelegramLink::class)->whereNull('revoked_at');
    }

    public function delete(): ?bool
    {
        if ($this->isTheLastAdmin()) {
            throw new LastAdmin;
        }

        return parent::delete();
    }

    public function isTheLastAdmin(): bool
    {
        if (! $this->hasRole(Access::ADMIN)) {
            return false;
        }

        return static::role(Access::ADMIN)->whereKeyNot($this->getKey())->doesntExist();
    }
}
