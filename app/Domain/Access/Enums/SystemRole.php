<?php

declare(strict_types=1);

namespace App\Domain\Access\Enums;

/**
 * Starter role set: ФО §4.1 plus the three roles added by Д-17.
 * "Direct manager" and "guest" are relations / anonymous access, not roles.
 */
enum SystemRole: string
{
    case SuperAdmin = 'super_admin';
    case OrgHead = 'org_head';
    case UnitHead = 'unit_head';
    case Employee = 'employee';
    case Volunteer = 'volunteer';
    case Candidate = 'candidate';
    case Hr = 'hr';
    case Security = 'security';
    case Psychologist = 'psychologist';
    case CatalogAdmin = 'catalog_admin';
    case InboxOperator = 'inbox_operator';
    case Moderator = 'moderator';

    /**
     * @return array<string, string>
     */
    public function names(): array
    {
        return match ($this) {
            self::SuperAdmin => ['ro' => 'Superadministrator', 'ru' => 'Суперадмин', 'en' => 'Super administrator'],
            self::OrgHead => ['ro' => 'Conducătorul organizației', 'ru' => 'Руководитель организации', 'en' => 'Head of organization'],
            self::UnitHead => ['ro' => 'Conducătorul subdiviziunii / regiunii', 'ru' => 'Руководитель подразделения / региона', 'en' => 'Head of unit / region'],
            self::Employee => ['ro' => 'Angajat / activist', 'ru' => 'Сотрудник / активист', 'en' => 'Employee / activist'],
            self::Volunteer => ['ro' => 'Voluntar', 'ru' => 'Волонтёр', 'en' => 'Volunteer'],
            self::Candidate => ['ro' => 'Candidat', 'ru' => 'Кандидат', 'en' => 'Candidate'],
            self::Hr => ['ro' => 'Resurse umane', 'ru' => 'HR', 'en' => 'HR'],
            self::Security => ['ro' => 'Serviciul de securitate', 'ru' => 'Служба безопасности', 'en' => 'Security service'],
            self::Psychologist => ['ro' => 'Psiholog', 'ru' => 'Психолог', 'en' => 'Psychologist'],
            self::CatalogAdmin => ['ro' => 'Administrator de nomenclatoare', 'ru' => 'Администратор справочников', 'en' => 'Catalog administrator'],
            self::InboxOperator => ['ro' => 'Operator mesaje primite', 'ru' => 'Оператор входящих', 'en' => 'Inbox operator'],
            self::Moderator => ['ro' => 'Moderator', 'ru' => 'Модератор', 'en' => 'Moderator'],
        };
    }
}
