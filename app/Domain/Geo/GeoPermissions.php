<?php

declare(strict_types=1);

namespace App\Domain\Geo;

/**
 * Permission codes of the Geo module: the territory reference (docs/security/permission-catalog.md §3.1) and the
 * field work (§9).
 */
final class GeoPermissions
{
    private const array LEADERS = ['super_admin', 'org_head', 'unit_head'];

    /**
     * "Св": an agitator works with the houses they answer for; "Т" on the map — with their own territories.
     * HR holds the same by relation: HR gives people the roles of employee and volunteer, and nobody can grant
     * more than they hold themselves (Д-17).
     */
    private const array AGITATORS = ['employee:related', 'volunteer:related', 'hr:related'];

    private const array EVERYONE_BUT_CANDIDATE = [
        'employee', 'volunteer', 'hr', 'security', 'psychologist', 'catalog_admin', 'inbox_operator', 'moderator',
    ];

    /**
     * @return list<array{code: string, roles?: list<string>, reserved?: bool}>
     */
    public static function definitions(): array
    {
        return [
            ['code' => 'territories.read', 'roles' => ['*']],
            ['code' => 'territories.manage', 'roles' => ['super_admin', 'catalog_admin']],
            ['code' => 'territories.import', 'roles' => ['super_admin']],
            ['code' => 'territories.responsible.assign', 'roles' => ['super_admin', 'org_head', 'unit_head']],

            ['code' => 'geo.map.read', 'roles' => [...self::LEADERS, ...self::AGITATORS]],
            ['code' => 'geo.houses.read', 'roles' => [...self::LEADERS, ...self::AGITATORS]],
            ['code' => 'geo.houses.manage', 'roles' => self::LEADERS],
            ['code' => 'geo.assignments.manage', 'roles' => self::LEADERS],
            // A visit is recorded by whoever answers for the house — a head too only in a house of their own.
            ['code' => 'geo.visits.create', 'roles' => ['org_head:related', 'unit_head:related', ...self::AGITATORS]],
            ['code' => 'geo.visits.read', 'roles' => [...self::LEADERS, ...self::AGITATORS]],
            ['code' => 'geo.notes.team.read', 'roles' => self::LEADERS],
            ['code' => 'geo.summary.read', 'roles' => self::LEADERS],
            ['code' => 'geo.zones.read', 'roles' => [...self::LEADERS, ...array_map(fn (string $role): string => $role.':related', self::EVERYONE_BUT_CANDIDATE)]],
            ['code' => 'geo.zones.manage', 'roles' => self::LEADERS],
            ['code' => 'geo.addresses.manage', 'roles' => ['super_admin', 'catalog_admin']],

            // Д-22: a participant shares where they are by their own will; those who lead them may look.
            ['code' => 'geo.locations.share', 'roles' => ['super_admin', 'org_head', 'unit_head', ...self::EVERYONE_BUT_CANDIDATE]],
            ['code' => 'geo.locations.read', 'roles' => ['org_head', 'unit_head']],
            ['code' => 'geo.vehicles.read', 'roles' => self::LEADERS],
            ['code' => 'geo.vehicles.manage', 'roles' => self::LEADERS],
        ];
    }
}
