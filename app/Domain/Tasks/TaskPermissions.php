<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

/**
 * Permission codes of tasks (docs/security/permission-catalog.md §4.2). "related" = creator, assignee,
 * watcher or member of the task's project; the creator also updates, reopens and deletes by relation.
 */
final class TaskPermissions
{
    /**
     * @return list<array{code: string, roles?: list<string>, reserved?: bool}>
     */
    public static function definitions(): array
    {
        return [
            ['code' => 'tasks.read', 'roles' => ['super_admin', 'org_head', 'unit_head', 'employee:related', 'volunteer:related', 'hr:related', 'security:related', 'psychologist:related', 'catalog_admin:related', 'inbox_operator:related', 'moderator:related']],
            ['code' => 'tasks.create', 'roles' => ['super_admin', 'org_head', 'unit_head', 'employee:related', 'hr:related', 'security:related']],
            // Д-15: assignees are active users only; an employee assigns only themselves.
            ['code' => 'tasks.assign', 'roles' => ['super_admin', 'org_head', 'unit_head', 'employee:own', 'hr:own', 'security:own']],
            ['code' => 'tasks.update', 'roles' => ['super_admin', 'org_head', 'unit_head']],
            ['code' => 'tasks.status.change', 'roles' => ['super_admin', 'org_head', 'unit_head', 'employee:related', 'volunteer:related', 'hr:related', 'security:related', 'psychologist:related']],
            ['code' => 'tasks.reopen', 'roles' => ['super_admin', 'org_head', 'unit_head']],
            ['code' => 'tasks.delete', 'roles' => ['super_admin', 'org_head', 'unit_head']],
            ['code' => 'tasks.bulk', 'roles' => ['super_admin', 'org_head', 'unit_head']],
            ['code' => 'tasks.time.log', 'roles' => ['unit_head', 'employee:related', 'volunteer:related', 'hr:related']],
            ['code' => 'tasks.time.read_others', 'roles' => ['super_admin', 'org_head', 'unit_head']],
            ['code' => 'tasks.workflow.manage', 'roles' => ['super_admin', 'catalog_admin']],
        ];
    }
}
