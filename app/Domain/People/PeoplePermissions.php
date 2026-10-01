<?php

declare(strict_types=1);

namespace App\Domain\People;

/**
 * Permission codes of the People module (docs/security/permission-catalog.md §3.2–3.3).
 * Field groups (people.fields.*) are the administrator's half of field visibility (Д-13);
 * the owner's choice is the other half. Confidential layers are separate entities (ФО §6.3.4).
 */
final class PeoplePermissions
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
            ['code' => 'profile.own.update', 'roles' => [
                'super_admin', 'org_head', 'unit_head', 'employee', 'volunteer', 'candidate',
                'hr', 'security', 'psychologist', 'catalog_admin', 'inbox_operator', 'moderator',
            ]],
            ['code' => 'people.read', 'roles' => [
                'super_admin', 'org_head', 'hr', 'security', 'employee', 'unit_head',
                'psychologist', 'catalog_admin', 'inbox_operator', 'moderator',
                'volunteer:related', 'candidate:own',
            ]],
            ['code' => 'people.create', 'roles' => ['super_admin', 'org_head', 'hr', 'unit_head']],
            ['code' => 'people.update', 'roles' => ['super_admin', 'org_head', 'hr', 'unit_head']],
            ['code' => 'people.archive', 'roles' => ['super_admin', 'org_head']],
            ['code' => 'people.export', 'roles' => ['super_admin', 'org_head']],
            ['code' => 'people.import', 'roles' => ['super_admin', 'hr']],
            ['code' => 'people.status_history.read', 'roles' => ['super_admin', 'org_head', 'hr', 'unit_head']],
            // Д-10: "possibly the same person" hints; the existing person's direct manager sees them by relation.
            ['code' => 'people.link_hints.read', 'roles' => ['super_admin', 'org_head', 'hr']],

            ['code' => 'people.fields.contacts.read', 'roles' => [
                'super_admin', 'org_head', 'unit_head', 'employee', 'hr', 'security', 'psychologist',
                'catalog_admin', 'inbox_operator', 'moderator',
            ]],
            ['code' => 'people.fields.messengers.read', 'roles' => self::ALL_BUT_CANDIDATE],
            ['code' => 'people.fields.personal.read', 'roles' => ['super_admin', 'org_head', 'hr', 'security', 'unit_head', 'employee']],
            ['code' => 'people.fields.skills.read', 'roles' => self::ALL_BUT_CANDIDATE],

            // Confidential layers (ФО §6.3.2–6.3.4, §8). The direct manager reads some of them by relation (Д-11).
            ['code' => 'profile.internal.read', 'roles' => ['security', 'org_head']],
            ['code' => 'profile.internal.update', 'roles' => ['security', 'org_head']],
            ['code' => 'profile.hr.read', 'roles' => ['hr', 'org_head']],
            ['code' => 'profile.hr.write', 'roles' => ['hr']],
            ['code' => 'profile.psychology.write', 'roles' => ['psychologist']],
            ['code' => 'profile.psychology.read_all', 'reserved' => true],
            ['code' => 'profile.security.read', 'roles' => ['security', 'org_head']],
            ['code' => 'profile.security.write', 'roles' => ['security']],
            ['code' => 'notes360.create', 'roles' => ['employee', 'unit_head', 'hr', 'security', 'psychologist', 'org_head']],
            ['code' => 'notes360.feedback.read_hr', 'reserved' => true],
            ['code' => 'profile.layers.export', 'reserved' => true],
        ];
    }
}
