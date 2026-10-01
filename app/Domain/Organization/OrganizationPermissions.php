<?php

declare(strict_types=1);

namespace App\Domain\Organization;

/**
 * Permission codes of the organization structure (docs/security/permission-catalog.md §3.1, Д-11).
 */
final class OrganizationPermissions
{
    private const array ALL_BUT_CANDIDATE = [
        'super_admin', 'org_head', 'unit_head', 'employee', 'volunteer', 'hr', 'security',
        'psychologist', 'catalog_admin', 'inbox_operator', 'moderator',
    ];

    /**
     * @return list<array{code: string, roles?: list<string>, reserved?: bool}>
     */
    public static function definitions(): array
    {
        return [
            ['code' => 'org_units.read', 'roles' => self::ALL_BUT_CANDIDATE],
            ['code' => 'org_units.manage', 'roles' => ['super_admin', 'org_head']],
            ['code' => 'org_units.assign_head', 'roles' => ['super_admin', 'org_head']],
            ['code' => 'org_units.territories.manage', 'roles' => ['super_admin', 'org_head']],
            ['code' => 'people.transfer', 'roles' => ['super_admin', 'org_head', 'hr', 'unit_head']],
            ['code' => 'people.manager.change', 'roles' => ['super_admin', 'org_head', 'unit_head']],
        ];
    }
}
