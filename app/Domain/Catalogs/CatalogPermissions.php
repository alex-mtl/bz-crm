<?php

declare(strict_types=1);

namespace App\Domain\Catalogs;

/**
 * Permission codes of the Catalogs module (docs/security/permission-catalog.md §2.4, Д-16).
 */
final class CatalogPermissions
{
    /**
     * @return list<array{code: string, roles?: list<string>, reserved?: bool}>
     */
    public static function definitions(): array
    {
        return [
            ['code' => 'catalogs.read', 'roles' => [
                'super_admin', 'org_head', 'unit_head', 'employee', 'volunteer', 'candidate',
                'hr', 'security', 'psychologist', 'catalog_admin', 'inbox_operator', 'moderator',
            ]],
            ['code' => 'catalogs.propose', 'roles' => ['org_head', 'unit_head', 'hr', 'security', 'moderator']],
            ['code' => 'catalogs.review', 'roles' => ['super_admin', 'catalog_admin']],
            ['code' => 'catalogs.manage', 'roles' => ['super_admin', 'catalog_admin']],
        ];
    }
}
