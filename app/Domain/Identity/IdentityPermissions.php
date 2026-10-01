<?php

declare(strict_types=1);

namespace App\Domain\Identity;

/**
 * Permission codes of the Identity module (docs/security/permission-catalog.md §2.1).
 */
final class IdentityPermissions
{
    /**
     * @return list<array{code: string, roles?: list<string>, reserved?: bool}>
     */
    public static function definitions(): array
    {
        return [
            ['code' => 'users.read', 'roles' => ['super_admin', 'org_head', 'unit_head', 'hr', 'security']],
            ['code' => 'users.invite', 'roles' => ['super_admin', 'org_head', 'unit_head', 'hr']],
            ['code' => 'users.approve', 'roles' => ['super_admin', 'org_head', 'hr']],
            ['code' => 'users.deactivate', 'roles' => ['super_admin', 'org_head', 'hr']],
            ['code' => 'users.link_accounts', 'roles' => ['super_admin', 'org_head', 'hr']],
            ['code' => 'users.sessions.terminate', 'roles' => ['super_admin', 'security']],
            ['code' => 'users.password.reset', 'roles' => ['super_admin', 'security']],
            ['code' => 'users.2fa.reset', 'roles' => ['super_admin', 'security']],
            ['code' => 'users.login_history.read', 'roles' => ['super_admin', 'security']],
            ['code' => 'users.impersonate', 'roles' => ['super_admin']],   // Д-19
            ['code' => 'auth.providers.manage', 'roles' => ['super_admin']],
            ['code' => 'auth.policies.manage', 'roles' => ['super_admin', 'security']],
        ];
    }
}
