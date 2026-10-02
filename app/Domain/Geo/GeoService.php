<?php

declare(strict_types=1);

namespace App\Domain\Geo;

use App\Domain\Geo\Exceptions\GeoRuleViolation;
use App\Domain\Geo\Models\GeoZone;
use App\Domain\Geo\Models\Territory;
use App\Domain\Geo\Models\TerritoryBoundary;
use Illuminate\Database\Eloquent\Collection;
use RuntimeException;

/**
 * The only entry point for geometry (ТЗ §32): outlines are stored and read here, and every spatial question —
 * "is the point inside", "which zones stand here", "how far" — is answered here, in plain PHP over GeoJSON.
 * Nothing else touches coordinates of outlines, so the engine behind (MySQL spatial, PostGIS) can change
 * without the business API changing.
 */
final class GeoService
{
    public const string BOUNDARY_ATTRIBUTION = 'geoBoundaries (gbOpen MDA ADM1, UNHCR 2020), CC BY 3.0 IGO';

    public const string POINT_ATTRIBUTION = 'GeoNames (geonames.org), CC BY 4.0';

    /** A zone outline is drawn by hand: more corners than this is a mistake, not a zone. */
    public const int MAX_ZONE_POINTS = 500;

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
     * The outlines of every territory that has one — for the map layer.
     *
     * @return list<array{id: int, name: string, geometry: array<string, mixed>}>
     */
    public function boundaries(): array
    {
        $names = Territory::query()->whereIn('id', TerritoryBoundary::query()->select('territory_id'))->get()->keyBy('id');

        return TerritoryBoundary::query()->get()
            ->filter(fn (TerritoryBoundary $boundary): bool => $names->has($boundary->territory_id))
            ->map(fn (TerritoryBoundary $boundary): array => [
                'id' => $boundary->territory_id,
                'name' => $names[$boundary->territory_id]->name(),
                'geometry' => (array) json_decode($boundary->geojson, true),
            ])->values()->all();
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

    /**
     * Builds the outline of a zone from the corners a person put on the map, and checks it.
     *
     * @param  array<array-key, mixed>  $corners  each: latitude, longitude
     * @return array{geometry: array{type: string, coordinates: list<list<array{0: float, 1: float}>>}, box: array{min_latitude: float, max_latitude: float, min_longitude: float, max_longitude: float}}
     */
    public function outline(array $corners): array
    {
        $ring = [];
        foreach ($corners as $corner) {
            if (! is_array($corner) || ! isset($corner[0], $corner[1]) || ! is_numeric($corner[0]) || ! is_numeric($corner[1])) {
                throw GeoRuleViolation::because('invalid_outline');
            }
            [$latitude, $longitude] = [(float) $corner[0], (float) $corner[1]];
            if (abs($latitude) > 90 || abs($longitude) > 180) {
                throw GeoRuleViolation::because('invalid_outline');
            }
            // GeoJSON keeps longitude first.
            $position = [round($longitude, 6), round($latitude, 6)];
            if ($ring === [] || $ring[count($ring) - 1] !== $position) {
                $ring[] = $position;
            }
        }
        if (count($ring) > 1 && $ring[0] === $ring[count($ring) - 1]) {
            array_pop($ring);
        }
        if (count($ring) < 3 || count($ring) > self::MAX_ZONE_POINTS) {
            throw GeoRuleViolation::because('invalid_outline');
        }
        $ring[] = $ring[0];

        $longitudes = array_column($ring, 0);
        $latitudes = array_column($ring, 1);
        if (max($latitudes) - min($latitudes) < 0.000001 || max($longitudes) - min($longitudes) < 0.000001) {
            throw GeoRuleViolation::because('invalid_outline');
        }

        return [
            'geometry' => ['type' => 'Polygon', 'coordinates' => [$ring]],
            'box' => [
                'min_latitude' => min($latitudes), 'max_latitude' => max($latitudes),
                'min_longitude' => min($longitudes), 'max_longitude' => max($longitudes),
            ],
        ];
    }

    /**
     * @return array<string, mixed> GeoJSON geometry of the zone
     */
    public function zoneGeometry(GeoZone $zone): array
    {
        return (array) json_decode($zone->geometry, true);
    }

    /**
     * The corners of a zone as the map editor takes them.
     *
     * @return list<array{0: float, 1: float}> latitude, longitude
     */
    public function zoneCorners(GeoZone $zone): array
    {
        $ring = $this->zoneGeometry($zone)['coordinates'][0] ?? [];
        array_pop($ring);

        return array_map(fn (array $position): array => [(float) $position[1], (float) $position[0]], array_values($ring));
    }

    public function zoneContains(GeoZone $zone, float $latitude, float $longitude): bool
    {
        if ($latitude < (float) $zone->min_latitude || $latitude > (float) $zone->max_latitude
            || $longitude < (float) $zone->min_longitude || $longitude > (float) $zone->max_longitude) {
            return false;
        }

        return $this->contains($this->zoneGeometry($zone), $latitude, $longitude);
    }

    /**
     * The active zones a point stands in. The box narrows the candidates in SQL, the outline decides.
     *
     * @return Collection<int, GeoZone>
     */
    public function zonesAt(float $latitude, float $longitude): Collection
    {
        return GeoZone::query()->active()
            ->where('min_latitude', '<=', $latitude)->where('max_latitude', '>=', $latitude)
            ->where('min_longitude', '<=', $longitude)->where('max_longitude', '>=', $longitude)
            ->get()->filter(fn (GeoZone $zone): bool => $this->contains($this->zoneGeometry($zone), $latitude, $longitude))->values();
    }

    /**
     * Is the point inside a GeoJSON Polygon or MultiPolygon (holes respected). A point on the border counts as inside
     * or outside by the ray — good enough for zones drawn by hand.
     *
     * @param  array<string, mixed>  $geometry
     */
    public function contains(array $geometry, float $latitude, float $longitude): bool
    {
        $polygons = match ($geometry['type'] ?? null) {
            'Polygon' => [$geometry['coordinates'] ?? []],
            'MultiPolygon' => $geometry['coordinates'] ?? [],
            default => [],
        };

        foreach ($polygons as $rings) {
            if (! is_array($rings) || $rings === [] || ! self::inRing($rings[0], $latitude, $longitude)) {
                continue;
            }
            $inHole = false;
            foreach (array_slice($rings, 1) as $hole) {
                $inHole = $inHole || self::inRing($hole, $latitude, $longitude);
            }
            if (! $inHole) {
                return true;
            }
        }

        return false;
    }

    /**
     * Metres between two points (haversine).
     */
    public function distance(float $latitudeA, float $longitudeA, float $latitudeB, float $longitudeB): float
    {
        $dLat = deg2rad($latitudeB - $latitudeA);
        $dLng = deg2rad($longitudeB - $longitudeA);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($latitudeA)) * cos(deg2rad($latitudeB)) * sin($dLng / 2) ** 2;

        return 6371000.0 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * Ray casting over one ring of [longitude, latitude] positions.
     *
     * @param  mixed  $ring
     */
    private static function inRing($ring, float $latitude, float $longitude): bool
    {
        if (! is_array($ring)) {
            return false;
        }
        $inside = false;
        $count = count($ring);
        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            [$xi, $yi] = [(float) $ring[$i][0], (float) $ring[$i][1]];
            [$xj, $yj] = [(float) $ring[$j][0], (float) $ring[$j][1]];
            if (($yi > $latitude) !== ($yj > $latitude) && $longitude < ($xj - $xi) * ($latitude - $yi) / ($yj - $yi) + $xi) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }
}
