<?php

namespace App\Models;

use App\Casts\EncryptedIdentifier;
use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $name
 * @property string $kind
 * @property string $currency
 * @property string|null $bank
 * @property string|null $account_name
 * @property string|null $account_number
 * @property int|null $holder_user_id
 * @property bool $is_active
 * @property bool $show_on_invoices
 */
class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use HasFactory;

    public const BANK = 'bank';

    public const CASH = 'cash';

    public const LEADER_CASH = 'leader_cash';

    /** @var array<string, string> */
    public const KINDS = [
        self::BANK => 'Bank account',
        self::CASH => 'Cash box',
        self::LEADER_CASH => 'Group leader float',
    ];

    /** @var list<string> */
    protected $fillable = [
        'name', 'kind', 'currency', 'bank', 'account_name', 'account_number',
        'holder_user_id', 'is_active', 'show_on_invoices', 'sort_order',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'show_on_invoices' => 'boolean',
            'account_number' => EncryptedIdentifier::class,
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function holder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'holder_user_id');
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }

    /** The last four digits, for lists where the whole number has no business being. */
    public function maskedNumber(): ?string
    {
        $number = $this->account_number;

        if ($number === null) {
            return null;
        }

        return str_repeat('•', max(0, mb_strlen($number) - 4)).mb_substr($number, -4);
    }
}
