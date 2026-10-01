<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

/**
 * Permission codes of notifications (docs/security/permission-catalog.md §7).
 */
final class NotificationPermissions
{
    /**
     * @return list<array{code: string, roles?: list<string>, reserved?: bool}>
     */
    public static function definitions(): array
    {
        return [
            ['code' => 'notifications.read', 'roles' => ['*']],
            ['code' => 'notifications.preferences', 'roles' => ['*']],
            // "Только получатели в области": the scope of the role decides who can be told.
            ['code' => 'notifications.broadcast', 'roles' => ['super_admin', 'org_head', 'unit_head']],
            ['code' => 'notifications.critical.send', 'roles' => ['super_admin', 'org_head', 'security']],
            ['code' => 'notifications.defaults.manage', 'roles' => ['super_admin']],
        ];
    }
}
