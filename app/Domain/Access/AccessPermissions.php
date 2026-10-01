<?php

declare(strict_types=1);

namespace App\Domain\Access;

/**
 * Permission codes of the Access module (docs/security/permission-catalog.md §2.2).
 */
final class AccessPermissions
{
    /**
     * @return list<array{code: string, roles?: list<string>, reserved?: bool}>
     */
    public static function definitions(): array
    {
        return [
            ['code' => 'roles.read', 'roles' => ['super_admin', 'org_head', 'unit_head', 'hr', 'security']],
            ['code' => 'roles.manage', 'roles' => ['super_admin']],
            ['code' => 'roles.assign', 'roles' => ['super_admin', 'org_head', 'unit_head']],
            ['code' => 'delegations.manage', 'roles' => ['super_admin', 'org_head', 'unit_head']],
            // The direct manager also grants by relation, without this code (Д-3, Д-11).
            ['code' => 'access.territories.grant', 'roles' => ['super_admin', 'org_head', 'unit_head']],
            ['code' => 'access.simulate', 'roles' => ['super_admin', 'security']],
            // Д-13: which roles see which field groups — the administrator half of field visibility.
            ['code' => 'access.field_rules.manage', 'roles' => ['super_admin']],
        ];
    }
}
