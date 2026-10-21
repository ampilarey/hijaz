<?php

namespace App\Support;

/**
 * Who staff are and what they may do.
 *
 * Permissions are verbs (`rate.update`), not screens, so a policy can ask
 * the same question however an action is reached: the portal, the Telegram
 * bot, or a console command. Only verbs for functionality that exists are
 * listed; each phase adds its own.
 *
 * Role sets are built by inclusion. A role lists what it may do, never
 * "everything except", so adding a permission cannot leak it to a role that
 * was never meant to have it. Admin holds no permissions here at all: it is
 * granted everything by Gate::before in AppServiceProvider.
 */
final class Access
{
    public const ADMIN = 'Admin';

    public const OPERATIONS = 'Operations';

    public const FINANCE = 'Finance';

    public const REGISTRATION = 'Registration';

    public const GROUP_LEADER = 'Group leader';

    public const READ_ONLY = 'Read only';

    /** @var list<string> */
    public const ROLES = [
        self::ADMIN,
        self::OPERATIONS,
        self::FINANCE,
        self::REGISTRATION,
        self::GROUP_LEADER,
        self::READ_ONLY,
    ];

    /** Roles whose holders must set up an authenticator app before using the portal. */
    public const SECOND_FACTOR_ROLES = [self::ADMIN, self::FINANCE];

    /** @var list<string> */
    public const PERMISSIONS = [
        'portal.access',

        'user.viewAny',
        'user.view',
        'user.create',
        'user.update',
        'user.delete',

        'audit.viewAny',

        'setting.view',
        'setting.update',

        'island.viewAny',
        'island.view',
        'island.create',
        'island.update',

        'rate.viewAny',
        'rate.view',
        'rate.create',
        'rate.update',

        'account.viewAny',
        'account.view',
        'account.create',
        'account.update',

        'telegram.link',
    ];

    /**
     * @return array<string, list<string>>
     */
    public static function matrix(): array
    {
        $everyone = ['portal.access', 'telegram.link'];
        $reference = ['island.viewAny', 'island.view', 'rate.viewAny', 'rate.view', 'account.viewAny', 'account.view', 'setting.view'];

        return [
            self::ADMIN => [],

            self::OPERATIONS => [
                ...$everyone,
                ...$reference,
                'island.create',
                'island.update',
            ],

            self::FINANCE => [
                ...$everyone,
                ...$reference,
                'rate.create',
                'rate.update',
                'account.create',
                'account.update',
            ],

            self::REGISTRATION => [
                ...$everyone,
                'island.viewAny',
                'island.view',
            ],

            self::GROUP_LEADER => [
                ...$everyone,
            ],

            self::READ_ONLY => [
                ...$everyone,
                ...$reference,
            ],
        ];
    }
}
