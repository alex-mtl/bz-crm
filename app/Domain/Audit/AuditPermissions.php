<?php

declare(strict_types=1);

namespace App\Domain\Audit;

/**
 * Permission codes of the Audit module (docs/security/permission-catalog.md §2.3).
 */
final class AuditPermissions
{
    /**
     * @return list<array{code: string, roles?: list<string>, reserved?: bool}>
     */
    public static function definitions(): array
    {
        return [
            ['code' => 'audit.read', 'roles' => ['super_admin', 'org_head', 'security']],
            // Events of viewing confidential layers (ФО §6.3.4) — hidden from other journal readers.
            ['code' => 'audit.read.confidential_access', 'roles' => ['super_admin', 'org_head', 'security']],
            ['code' => 'audit.export', 'roles' => ['super_admin', 'security']],
            ['code' => 'audit.settings.manage', 'roles' => ['super_admin']],
        ];
    }
}
