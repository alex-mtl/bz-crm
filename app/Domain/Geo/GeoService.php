<?php

declare(strict_types=1);

namespace App\Domain\Geo;

use App\Domain\Geo\Models\Territory;
use App\Domain\Geo\Models\TerritoryBoundary;
use RuntimeException;

/**
 * The only entry point for geometry (ТЗ §32): outlines are stored and read here, nowhere else.
 * Phase 7 adds points, geofences and maps on top of the same service.
 */
final class GeoService
{
    public const string BOUNDARY_ATTRIBUTION = 'geoBoundaries (gbOpen MDA ADM1, UNHCR 2020), CC BY 3.0 IGO';

    public const string POINT_ATTRIBUTION = 'GeoNames (geonames.org), CC BY 4.0';

    /**
     * @param  array<string, Territory>  $territoriesBySlug
     */
    public function importBoundaries(string $file, array $territoriesBySlug): int
    {
        $data = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($data) || ! isset($data['features']) || ! is_array($data['features'])) {
            throw new RuntimeException("[{$file}] is not a GeoJSON FeatureCollection.");
        }

        $count = 0;
        foreach ($data['features'] as $feature) {
            $slug = $feature['properties']['slug'] ?? null;
            if (! is_string($slug) || ! isset($territoriesBySlug[$slug])) {
                continue;
            }
            TerritoryBoundary::query()->updateOrCreate(
                ['territory_id' => $territoriesBySlug[$slug]->id],
                ['geojson' => json_encode($feature['geometry'], JSON_THROW_ON_ERROR), 'source' => self::BOUNDARY_ATTRIBUTION],
            );
            $count++;
        }

        return $count;
    }

    /**
     * @return array<string, mixed>|null GeoJSON geometry
     */
    public function boundary(Territory $territory): ?array
    {
        $boundary = TerritoryBoundary::query()->find($territory->id);

        return $boundary !== null ? (array) json_decode($boundary->geojson, true) : null;
    }

    /**
     * @return array{0: float, 1: float}|null latitude, longitude
     */
    public function point(Territory $territory): ?array
    {
        return $territory->latitude !== null && $territory->longitude !== null
            ? [(float) $territory->latitude, (float) $territory->longitude]
            : null;
    }
}
