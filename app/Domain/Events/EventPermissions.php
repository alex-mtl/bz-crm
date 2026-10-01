<?php

declare(strict_types=1);

namespace App\Domain\Events;

/**
 * Permission codes of events (docs/security/permission-catalog.md §7). Seeing an event follows its visibility
 * (EventVisibility), not the scope of a role; the organizer manages their own event by relation.
 */
final class EventPermissions
{
    private const array LEADERS = ['super_admin', 'org_head', 'unit_head'];

    /**
     * @return list<array{code: string, roles?: list<string>, reserved?: bool}>
     */
    public static function definitions(): array
    {
        return [
            ['code' => 'events.read', 'roles' => [
                'super_admin', 'org_head', 'unit_head', 'employee', 'volunteer', 'hr', 'security', 'psychologist',
                'catalog_admin', 'inbox_operator', 'moderator', 'candidate:related',
            ]],
            // "Сотр (С — личные и в своих группах)": an employee holds private events and events of their own groups.
            ['code' => 'events.create', 'roles' => [...self::LEADERS, 'employee:own', 'hr:own']],
            ['code' => 'events.update', 'roles' => self::LEADERS],
            ['code' => 'events.invite', 'roles' => self::LEADERS],
            ['code' => 'events.invite.bulk', 'roles' => self::LEADERS],
            ['code' => 'events.rsvp', 'roles' => ['*']],
            ['code' => 'events.attendance.mark', 'roles' => self::LEADERS],
            ['code' => 'events.results.publish', 'roles' => self::LEADERS],
            ['code' => 'events.calendar.subscribe', 'roles' => ['*']],
        ];
    }
}
