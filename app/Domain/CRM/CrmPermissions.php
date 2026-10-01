<?php

declare(strict_types=1);

namespace App\Domain\CRM;

/**
 * Permission codes of the CRM (docs/security/permission-catalog.md §5). "related" = the responsible of a lead or
 * an appeal; the applicant sees their own appeals ("own").
 */
final class CrmPermissions
{
    private const array LEADERS = ['super_admin', 'org_head', 'unit_head'];

    private const array OPERATORS = ['super_admin', 'org_head', 'unit_head', 'inbox_operator'];

    /**
     * Employees work with what they are responsible for. HR holds the same by relation: HR invites employees,
     * and nobody can grant more than they hold themselves (Д-17); ФО §4.1 also gives HR the candidates pipeline.
     */
    private const array BY_RELATION = ['employee:related', 'hr:related'];

    /**
     * @return list<array{code: string, roles?: list<string>, reserved?: bool}>
     */
    public static function definitions(): array
    {
        return [
            ['code' => 'crm.interactions.read', 'roles' => ['super_admin', 'org_head', 'hr', 'unit_head', 'inbox_operator']],
            ['code' => 'crm.interactions.create', 'roles' => ['super_admin', 'org_head', 'hr', 'unit_head', 'inbox_operator', 'employee:related']],
            ['code' => 'crm.relations.manage', 'roles' => ['super_admin', 'org_head', 'hr', 'unit_head']],
            ['code' => 'crm.duplicates.review', 'roles' => ['super_admin', 'org_head', 'hr', 'unit_head']],
            ['code' => 'crm.people.merge', 'roles' => ['super_admin', 'hr']],
            ['code' => 'crm.import', 'roles' => ['super_admin', 'hr']],

            ['code' => 'pipelines.read', 'roles' => [...self::OPERATORS, ...self::BY_RELATION]],
            ['code' => 'pipelines.write', 'roles' => [...self::OPERATORS, ...self::BY_RELATION]],
            ['code' => 'pipelines.manage', 'roles' => ['super_admin', 'org_head', 'catalog_admin']],
            ['code' => 'leads.create', 'roles' => self::OPERATORS],
            ['code' => 'leads.assign', 'roles' => self::OPERATORS],
            ['code' => 'leads.close', 'roles' => [...self::OPERATORS, ...self::BY_RELATION]],
            ['code' => 'leads.export', 'roles' => ['super_admin', 'org_head']],

            ['code' => 'appeals.read', 'roles' => [...self::OPERATORS, ...self::BY_RELATION, 'candidate:own']],
            ['code' => 'appeals.create', 'roles' => [...self::OPERATORS, ...self::BY_RELATION]],
            ['code' => 'appeals.assign', 'roles' => self::OPERATORS],
            ['code' => 'appeals.close', 'roles' => [...self::OPERATORS, ...self::BY_RELATION]],
            ['code' => 'appeals.export', 'roles' => ['super_admin', 'org_head']],

            ['code' => 'segments.read', 'roles' => [...self::LEADERS, 'hr', 'inbox_operator']],
            ['code' => 'segments.manage', 'roles' => [...self::LEADERS, 'hr']],
        ];
    }
}
