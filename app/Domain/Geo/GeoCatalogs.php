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
        ];
    }
}
