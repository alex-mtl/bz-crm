<?php

declare(strict_types=1);

namespace App\Domain\Projects;

/**
 * Permission codes of projects (docs/security/permission-catalog.md §4.1). "related" = project member;
 * the project manager updates the project, its members and budget by relation.
 */
final class ProjectPermissions
{
    /**
     * @return list<array{code: string, roles?: list<string>, reserved?: bool}>
     */
    public static function definitions(): array
    {
        return [
            ['code' => 'projects.read', 'roles' => ['super_admin', 'org_head', 'unit_head', 'employee:related', 'volunteer:related', 'hr:related', 'security:related']],
            ['code' => 'projects.create', 'roles' => ['super_admin', 'org_head', 'unit_head']],
            ['code' => 'projects.update', 'roles' => ['super_admin', 'org_head', 'unit_head']],
            ['code' => 'projects.members.manage', 'roles' => ['super_admin', 'org_head', 'unit_head']],
            ['code' => 'projects.budget.read', 'roles' => ['super_admin', 'org_head', 'unit_head']],
            ['code' => 'projects.budget.manage', 'roles' => ['super_admin', 'org_head', 'unit_head']],
            ['code' => 'projects.archive', 'roles' => ['super_admin', 'org_head', 'unit_head']],
            ['code' => 'projects.templates.manage', 'roles' => ['super_admin', 'org_head', 'catalog_admin']],
        ];
    }
}
