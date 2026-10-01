<?php

declare(strict_types=1);

namespace App\Filament\Resources\Roles\Pages;

use App\Domain\Access\Enums\PermissionEffect;
use App\Domain\Access\Models\Role;
use App\Domain\Access\Models\RolePermission;
use App\Domain\Access\PermissionRegistry;

/**
 * The form keeps permissions per module (allow.<module>[], deny.<module>[]); the domain wants flat lists.
 */
trait InteractsWithRolePermissions
{
    /**
     * @param  array<string, mixed>  $data
     * @return array{0: list<string>, 1: list<string>}
     */
    protected function flattenPermissions(array $data): array
    {
        $flatten = function (mixed $groups): array {
            $codes = [];
            foreach ((array) $groups as $group) {
                foreach ((array) $group as $code) {
                    $codes[] = (string) $code;
                }
            }

            return array_values(array_unique($codes));
        };

        return [$flatten($data['allow'] ?? []), $flatten($data['deny'] ?? [])];
    }

    /**
     * @return array{allow: array<string, list<string>>, deny: array<string, list<string>>}
     */
    protected function groupedPermissions(Role $role): array
    {
        $registry = app(PermissionRegistry::class);
        $grouped = ['allow' => [], 'deny' => []];
        foreach ($role->permissions()->get() as $permission) {
            /** @var RolePermission $permission */
            if (! $registry->has($permission->permission_code)) {
                continue;
            }
            $key = $permission->effect === PermissionEffect::Deny ? 'deny' : 'allow';
            $grouped[$key][$registry->get($permission->permission_code)->module][] = $permission->permission_code;
        }

        return $grouped;
    }
}
