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

        'trip.viewAny',
        'trip.view',
        'trip.create',
        'trip.update',
        'trip.transition',

        'checklist.viewAny',
        'checklist.view',
        'checklist.create',
        'checklist.update',
        'checklist.delete',
        'checklist.tick',

        'cost.viewAny',
        'cost.view',
        'cost.create',
        'cost.update',

        'customer.viewAny',
        'customer.view',
        'customer.create',
        'customer.update',

        'pilgrim.viewAny',
        'pilgrim.view',
        'pilgrim.create',
        'pilgrim.update',
        'pilgrim.verify',

        'document.viewAny',
        'document.view',
        'document.create',
        'document.accept',
        'document.verify',
        'document.download',

        'booking.viewAny',
        'booking.view',
        'booking.create',
        'booking.update',
        'booking.confirm',
        'booking.cancel',
        'booking.discount',

        'payment.viewAny',
        'payment.view',
        'payment.create',
        'payment.verify',
        'payment.refund',
        'payment.download',

        'excursionBooking.viewAny',
        'excursionBooking.view',
        'excursionBooking.create',
        'excursionBooking.update',

        'trip.override',

        'bus.viewAny',
        'bus.view',
        'bus.create',
        'bus.update',
        'bus.delete',
        'bus.assign',

        'room.viewAny',
        'room.view',
        'room.create',
        'room.update',
        'room.delete',
        'room.assign',

        'card.viewAny',
        'card.view',
        'card.create',
        'card.scan',

        'list.view',
        'readiness.view',
        'pilgrim.checkIn',
        'visa.update',

        'ledger.viewAny',
        'ledger.view',
        'ledger.create',
        'ledger.void',
        'ledgerCategory.viewAny',
        'ledgerCategory.view',
        'ledgerCategory.create',
        'ledgerCategory.update',
        'report.view',
        'usdList.update',
    ];

    /**
     * @return array<string, list<string>>
     */
    public static function matrix(): array
    {
        $everyone = ['portal.access', 'telegram.link', 'trip.viewAny', 'trip.view'];
        $reference = ['island.viewAny', 'island.view', 'rate.viewAny', 'rate.view', 'account.viewAny', 'account.view', 'setting.view'];
        $checklist = ['checklist.viewAny', 'checklist.view'];
        $costs = ['cost.viewAny', 'cost.view'];
        $people = ['customer.viewAny', 'customer.view', 'pilgrim.viewAny', 'pilgrim.view', 'document.viewAny', 'document.view'];
        $registration = ['customer.create', 'customer.update', 'pilgrim.create', 'pilgrim.update', 'document.create', 'document.accept', 'document.download'];
        $money = ['booking.viewAny', 'booking.view', 'payment.viewAny', 'payment.view', 'excursionBooking.viewAny', 'excursionBooking.view'];
        $books = ['ledger.viewAny', 'ledger.view', 'ledgerCategory.viewAny', 'ledgerCategory.view', 'report.view'];
        $ground = ['bus.viewAny', 'bus.view', 'room.viewAny', 'room.view', 'card.viewAny', 'card.view', 'list.view', 'readiness.view'];
        $operations = ['bus.create', 'bus.update', 'bus.delete', 'bus.assign', 'room.create', 'room.update', 'room.delete', 'room.assign', 'card.create', 'card.scan', 'pilgrim.checkIn', 'visa.update'];
        $desk = ['booking.create', 'booking.update', 'booking.confirm', 'booking.cancel', 'payment.create', 'payment.download', 'excursionBooking.create', 'excursionBooking.update'];

        return [
            self::ADMIN => [],

            self::OPERATIONS => [
                ...$everyone,
                ...$reference,
                ...$checklist,
                'island.create',
                'island.update',
                'trip.create',
                'trip.update',
                'trip.transition',
                'checklist.create',
                'checklist.update',
                'checklist.delete',
                'checklist.tick',
                ...$people,
                ...$registration,
                'pilgrim.verify',
                'document.verify',
                ...$money,
                ...$desk,
                ...$ground,
                ...$operations,
                'report.view',
                'usdList.update',
            ],

            self::FINANCE => [
                ...$everyone,
                ...$reference,
                ...$checklist,
                ...$costs,
                'rate.create',
                'rate.update',
                'account.create',
                'account.update',
                'cost.create',
                'cost.update',
                ...$people,
                ...$money,
                'booking.discount',
                'payment.create',
                'payment.verify',
                'payment.refund',
                'payment.download',
                ...$ground,
                ...$books,
                'ledger.create',
                'ledger.void',
                'ledgerCategory.create',
                'ledgerCategory.update',
                'usdList.update',
            ],

            self::REGISTRATION => [
                ...$everyone,
                'island.viewAny',
                'island.view',
                ...$people,
                ...$registration,
                ...$money,
                ...$desk,
                ...$ground,
                'card.create',
                'card.scan',
                'visa.update',
            ],

            self::GROUP_LEADER => [
                ...$everyone,
                ...$checklist,
                'checklist.tick',
                'pilgrim.viewAny',
                'pilgrim.view',
                ...$money,
                'excursionBooking.create',
                'excursionBooking.update',
                'payment.create',
                ...$ground,
                'card.scan',
                'pilgrim.checkIn',
            ],

            self::READ_ONLY => [
                ...$everyone,
                ...$reference,
                ...$checklist,
                ...$costs,
                ...$people,
                ...$money,
                ...$ground,
                ...$books,
            ],
        ];
    }
}
