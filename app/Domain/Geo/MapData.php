<?php

declare(strict_types=1);

namespace App\Domain\Geo;

use App\Domain\Access\AuthorizationService;
use App\Domain\Geo\Actions\LocationSharing;
use App\Domain\Geo\Actions\ManageVehicles;
use App\Domain\Geo\Actions\ManageZones;
use App\Domain\Geo\Models\GeoZone;
use App\Domain\Geo\Models\House;
use App\Domain\Geo\Models\LocationShare;
use App\Domain\Geo\Models\Vehicle;
use App\Domain\Identity\Models\User;

/**
 * Everything the map shows to one reader (ФО §6.11: «отображение домов/участков со статусами обхода»): houses
 * with the progress of the canvass, geozones, people who share their location, vehicles — each layer through
 * the right that governs it. The map never asks the database itself.
 */
final readonly class MapData
{
    public function __construct(
        private AuthorizationService $authorization,
        private FieldAccess $access,
        private CanvassSummary $summary,
        private ManageZones $zones,
        private LocationSharing $sharing,
        private ManageVehicles $vehicles,
        private GeoService $geo,
        private FieldSettings $settings,
    ) {}

    /**
     * @return array{tiles: array<string, mixed>, houses: list<array<string, mixed>>, zones: list<array<string, mixed>>, people: list<array<string, mixed>>, vehicles: list<array<string, mixed>>}
     */
    public function for(User $reader): array
    {
        return [
            'tiles' => $this->settings->map(),
            'houses' => $this->houses($reader),
            'zones' => $this->zones($reader),
            'people' => $this->people($reader),
            'vehicles' => $this->vehiclesOf($reader),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function houses(User $reader): array
    {
        if (! $this->authorization->can($reader, 'geo.map.read')) {
            return [];
        }
        $query = $this->access->houses($reader, 'geo.map.read')->active()->whereNotNull('houses.latitude');
        $figures = $this->summary->perHouse($query);
        // The card opens only for those who may read the house — the map alone shows the colour.
        $openable = $this->access->houses($reader, 'geo.houses.read')->pluck('houses.id')->flip();

        return (clone $query)->with('address.street')->limit(5000)->get()->map(fn (House $house): array => [
            'id' => $house->id,
            'lat' => (float) $house->latitude,
            'lng' => (float) $house->longitude,
            'label' => $house->label(),
            'apartments' => $figures[$house->id]['apartments'] ?? 0,
            'visited_pct' => $figures[$house->id]['visited_pct'] ?? 0,
            'supporter_pct' => $figures[$house->id]['supporter_pct'] ?? 0,
            'url' => $openable->has($house->id) ? '/admin/houses/'.$house->id : null,
        ])->values()->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function zones(User $reader): array
    {
        return $this->zones->visibleTo($reader)->active()->orderBy('name')->get()->map(fn (GeoZone $zone): array => [
            'id' => $zone->id, 'name' => $zone->name, 'color' => $zone->color,
            'geometry' => $this->geo->zoneGeometry($zone), 'url' => '/admin/geo-zones/'.$zone->id,
        ])->values()->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function people(User $reader): array
    {
        return $this->sharing->visibleTo($reader)->whereNotNull('last_latitude')->with('person')->get()
            ->map(fn (LocationShare $share): array => [
                'id' => $share->id, 'name' => $share->person->fullName(),
                'lat' => (float) $share->last_latitude, 'lng' => (float) $share->last_longitude,
                'at' => $share->last_point_at?->toIso8601String(), 'until' => $share->expires_at->toIso8601String(),
                'own' => $share->person_id === $reader->person_id,
            ])->values()->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function vehiclesOf(User $reader): array
    {
        return $this->vehicles->visibleTo($reader)->active()->whereNotNull('last_latitude')->get()
            ->map(fn (Vehicle $vehicle): array => [
                'id' => $vehicle->id, 'name' => $vehicle->name, 'plate' => $vehicle->plate,
                'lat' => (float) $vehicle->last_latitude, 'lng' => (float) $vehicle->last_longitude,
                'at' => $vehicle->last_point_at?->toIso8601String(), 'url' => '/admin/vehicles/'.$vehicle->id,
            ])->values()->all();
    }
}
