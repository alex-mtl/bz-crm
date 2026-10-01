<?php

declare(strict_types=1);

namespace App\Domain\Geo;

/**
 * Permission codes of the territory reference (docs/security/permission-catalog.md §3.1).
 */
final class GeoPermissions
{
    /**
     * @return list<array{code: string, roles?: list<string>, reserved?: bool}>
     */
    public static function definitions(): array
    {
        return [
            ['code' => 'territories.read', 'roles' => ['*']],
            ['code' => 'territories.manage', 'roles' => ['super_admin', 'catalog_admin']],
            ['code' => 'territories.import', 'roles' => ['super_admin']],
            ['code' => 'territories.responsible.assign', 'roles' => ['super_admin', 'org_head', 'unit_head']],
        ];
    }
}
