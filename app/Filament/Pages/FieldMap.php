<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Geo\Actions\LocationSharing;
use App\Domain\Geo\Actions\ManageVehicles;
use App\Domain\Geo\GeoService;
use App\Domain\Geo\MapData;
use App\Domain\Geo\Models\LocationPoint;
use App\Domain\Geo\Models\LocationShare;
use App\Domain\Geo\Models\Vehicle;
use App\Filament\Concerns\ChecksPermissions;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * The map of the field work (ФО §6.11): houses coloured by the progress of the canvass, geozones, the people
 * who share their location and the vehicles — each layer as far as the reader's rights reach (MapData).
 */
class FieldMap extends Page
{
    use ChecksPermissions;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMap;

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'field-map';

    protected string $view = 'filament.pages.field-map';

    public static function canAccess(): bool
    {
        return static::allows('geo.map.read');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.field');
    }

    public static function getNavigationLabel(): string
    {
        return __('geo.ui.map');
    }

    public function getTitle(): string
    {
        return __('geo.ui.map');
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $data = app(MapData::class)->for(static::actor());

        return [
            'people' => $data['people'],
            'vehicles' => $data['vehicles'],
            'houses' => count($data['houses']),
            'map' => [
                ...$data,
                'movers' => true,
                'boundaries' => true,
                'labels' => [
                    'houses' => __('geo.ui.houses'), 'zones' => __('geo.ui.zones'), 'movers' => __('geo.ui.movers'),
                    'boundaries' => __('geo.ui.boundaries'), 'apartments' => __('geo.ui.apartments'),
                    'visited' => __('geo.ui.visited'), 'supporters' => __('geo.ui.supporters'),
                ],
            ],
        ];
    }

    /**
     * Fresh positions of people and vehicles — the page asks every half a minute.
     */
    public function refreshMovers(): void
    {
        $data = app(MapData::class);
        $this->dispatch('bz-map-movers', people: $data->people(static::actor()), vehicles: $data->vehiclesOf(static::actor()));
    }

    /**
     * The outlines of the districts, asked by the map when the layer is turned on.
     *
     * @return list<array{id: int, name: string, geometry: array<string, mixed>}>
     */
    public function boundaries(): array
    {
        return app(GeoService::class)->boundaries();
    }

    /**
     * The track of a person's sharing: allowed and journaled by the domain.
     */
    public function showTrack(int $shareId): void
    {
        static::attempt(function () use ($shareId): void {
            $points = app(LocationSharing::class)->track(static::actor(), LocationShare::query()->findOrFail($shareId));
            $this->dispatch('bz-map-track', points: $points->map(fn (LocationPoint $point): array => [(float) $point->latitude, (float) $point->longitude])->all());
        });
    }

    public function showVehicleTrack(int $vehicleId): void
    {
        static::attempt(function () use ($vehicleId): void {
            $points = app(ManageVehicles::class)->track(static::actor(), Vehicle::query()->findOrFail($vehicleId));
            $this->dispatch('bz-map-track', points: $points->map(fn (LocationPoint $point): array => [(float) $point->latitude, (float) $point->longitude])->all());
        });
    }
}
