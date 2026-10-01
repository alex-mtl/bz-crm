<?php

declare(strict_types=1);

namespace App\Domain\Groups;

/**
 * Permission codes of groups (docs/security/permission-catalog.md §6). Managing and moderating a group come
 * from the role inside the group (owner, admin, moderator) — a relation, not a system role.
 */
final class GroupPermissions
{
    private const array ALL = [
        'super_admin', 'org_head', 'unit_head', 'employee', 'volunteer', 'candidate', 'hr', 'security',
        'psychologist', 'catalog_admin', 'inbox_operator', 'moderator',
    ];

    /**
     * @return list<array{code: string, roles?: list<string>, reserved?: bool}>
     */
    public static function definitions(): array
    {
        $allButCandidate = array_values(array_diff(self::ALL, ['candidate']));

        return [
            ['code' => 'groups.read', 'roles' => self::ALL],
            // ФО §6.5: "any user, with a configurable restriction by role" — the restriction is this right.
            ['code' => 'groups.create', 'roles' => array_values(array_diff(self::ALL, ['candidate', 'volunteer']))],
            ['code' => 'groups.join', 'roles' => $allButCandidate],
            // By relation: the owner and the admins of the group. The super admin holds the code like any other.
            ['code' => 'groups.manage', 'roles' => ['super_admin']],
            ['code' => 'groups.moderate', 'roles' => ['super_admin']],
            ['code' => 'groups.invite.bulk', 'roles' => ['super_admin', 'org_head', 'unit_head']],
            ['code' => 'groups.link.manage', 'roles' => ['super_admin', 'org_head', 'unit_head']],
        ];
    }
}
