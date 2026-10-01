<?php

declare(strict_types=1);

namespace App\Domain\CustomObjects;

/**
 * Permission codes of the constructor (docs/security/permission-catalog.md §11). In phase 3 only custom fields
 * of people exist; object types arrive in phase 9.
 */
final class CustomObjectPermissions
{
    /**
     * @return list<array{code: string, roles?: list<string>, reserved?: bool}>
     */
    public static function definitions(): array
    {
        return [
            ['code' => 'custom_objects.types.manage', 'roles' => ['super_admin', 'catalog_admin']],
        ];
    }
}
