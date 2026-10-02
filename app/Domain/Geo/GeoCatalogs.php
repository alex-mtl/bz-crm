<?php

declare(strict_types=1);

namespace App\Domain\Geo;

/**
 * Catalogs of the Geo module (Д-16): territory levels are configurable, depth can be added (Д-7).
 */
final class GeoCatalogs
{
    /**
     * @return list<array{code: string, properties?: array<string, 'bool'|'int'|'string'>, data?: string}>
     */
    public static function definitions(): array
    {
        return [
            ['code' => 'territory_levels', 'properties' => ['depth' => 'int'], 'data' => 'territory-levels.csv'],
            // ФО §6.11: "тип (многоквартирный/частный)"; a multi-unit house has flats, another one — a single household.
            ['code' => 'house_types', 'properties' => ['multi_unit' => 'bool'], 'data' => 'house-types.csv'],
            // The seven statuses of ФО §6.11; the properties are what the summaries count by.
            ['code' => 'canvass_statuses', 'properties' => [
                'visited' => 'bool', 'contact' => 'bool', 'supporter' => 'bool', 'retry' => 'bool', 'color' => 'string',
            ], 'data' => 'canvass-statuses.csv'],
            ['code' => 'street_types', 'properties' => ['abbr' => 'string'], 'data' => 'street-types.csv'],
            ['code' => 'vehicle_types', 'data' => 'vehicle-types.csv'],
        ];
    }
}
