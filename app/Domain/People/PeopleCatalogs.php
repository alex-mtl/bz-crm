<?php

declare(strict_types=1);

namespace App\Domain\People;

/**
 * Catalogs owned by the People module (Д-16).
 */
final class PeopleCatalogs
{
    /**
     * @return list<array{code: string, properties?: array<string, 'bool'|'int'|'string'>, data?: string}>
     */
    public static function definitions(): array
    {
        return [
            ['code' => 'person_types', 'data' => 'person-types.csv'],
            // ФО §6.3.1: extensible contact types; field_group ties a type to its field-group permission (Д-13).
            ['code' => 'contact_types', 'properties' => ['field_group' => 'string'], 'data' => 'contact-types.csv'],
            ['code' => 'profile_covers', 'properties' => ['color' => 'string'], 'data' => 'profile-covers.csv'],
        ];
    }
}
