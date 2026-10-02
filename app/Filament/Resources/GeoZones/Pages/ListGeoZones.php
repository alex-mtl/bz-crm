<?php

declare(strict_types=1);

namespace App\Filament\Resources\GeoZones\Pages;

use App\Domain\Geo\Actions\ManageZones;
use App\Domain\Geo\FieldSettings;
use App\Domain\Geo\Models\Territory;
use App\Domain\Identity\Models\User;
use App\Filament\Pages\FieldMap;
use App\Filament\Resources\GeoZones\GeoZoneResource;
use DomainException;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Auth\Access\AuthorizationException;

class ListGeoZones extends ListRecords
{
    protected static string $resource = GeoZoneResource::class;

    /** Half the side of the square a new zone starts as, in degrees of latitude (about 250 m). */
    private const float START_HALF_SIDE = 0.00225;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('map')->label(__('geo.ui.map'))->icon('heroicon-o-map')->color('gray')->url(fn (): string => FieldMap::getUrl())
                ->visible(fn (): bool => FieldMap::canAccess()),
            Action::make('create')->label(__('geo.ui.add_zone'))->icon('heroicon-o-plus')
                ->visible(fn (): bool => (bool) Filament::auth()->user()?->can('geo.zones.manage'))
                ->schema(GeoZoneResource::fields())
                ->action(function (array $data) {
                    $user = Filament::auth()->user();
                    assert($user instanceof User);
                    try {
                        $zone = app(ManageZones::class)->create($user, [
                            'name' => (string) $data['name'],
                            'territory_id' => filled($data['territory_id'] ?? null) ? (int) $data['territory_id'] : null,
                            'color' => $data['color'] ?? null,
                            'description' => $data['description'] ?? null,
                            'responsible_ids' => array_map('intval', (array) ($data['responsible_ids'] ?? [])),
                            'notify_events' => (bool) ($data['notify_events'] ?? true),
                            'notify_crossings' => (bool) ($data['notify_crossings'] ?? true),
                            'corners' => self::startingSquare($data),
                        ]);
                    } catch (AuthorizationException|DomainException $e) {
                        Notification::make()->title($e->getMessage() !== '' ? $e->getMessage() : __('access.denied'))->danger()->send();

                        return null;
                    }
                    Notification::make()->title(__('geo.ui.zone_created'))->success()->send();

                    // The outline is drawn on the map of the zone's own page.
                    return redirect(GeoZoneResource::getUrl('view', ['record' => $zone]).'?edit=1');
                }),
        ];
    }

    /**
     * A zone is born as a small square — around the point given, the point of its territory or the centre of the
     * map — and is then shaped by hand.
     *
     * @param  array<string, mixed>  $data
     * @return list<array{0: float, 1: float}>
     */
    private static function startingSquare(array $data): array
    {
        $center = app(FieldSettings::class)->map()['center'];
        if (filled($data['latitude'] ?? null) && filled($data['longitude'] ?? null)) {
            $center = [(float) $data['latitude'], (float) $data['longitude']];
        } elseif (filled($data['territory_id'] ?? null)) {
            $territory = Territory::query()->find($data['territory_id']);
            // A polling district has no point of its own: the nearest territory above that has one.
            while ($territory !== null && $territory->latitude === null) {
                $territory = $territory->parent;
            }
            $center = $territory !== null ? [(float) $territory->latitude, (float) $territory->longitude] : $center;
        }
        [$latitude, $longitude] = $center;
        $half = self::START_HALF_SIDE;
        $wide = $half / max(0.2, cos(deg2rad($latitude)));

        return [
            [$latitude + $half, $longitude - $wide], [$latitude + $half, $longitude + $wide],
            [$latitude - $half, $longitude + $wide], [$latitude - $half, $longitude - $wide],
        ];
    }
}
