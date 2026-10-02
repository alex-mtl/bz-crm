<?php

declare(strict_types=1);

namespace App\Domain\Geo;

use App\Domain\Geo\Events\GeoZoneCrossed;
use App\Domain\Geo\Models\GeoZone;
use App\Domain\Geo\Models\GeoZoneCrossing;
use App\Domain\Geo\Notifications\FieldNotice;
use App\Domain\People\Models\Person;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Turns points of a track into events: a participant or a vehicle entered a geozone, left a geozone (Д-22).
 * Who is inside which zone is remembered, so standing inside gives no events — only the crossing does.
 */
final readonly class ZoneWatcher
{
    public function __construct(private GeoService $geo) {}

    /**
     * @param  array<string, mixed>  $about  what the notice says about the mover: name, and share_id for a person
     * @return list<GeoZoneCrossing>
     */
    public function moved(string $moverType, int $moverId, float $latitude, float $longitude, Carbon $at, array $about = []): array
    {
        $now = $this->geo->zonesAt($latitude, $longitude)->keyBy('id');
        $before = DB::table('geo_zone_presences')->where('mover_type', $moverType)->where('mover_id', $moverId)->pluck('geo_zone_id')
            ->map(fn ($id): int => (int) $id)->all();

        $crossings = [];
        foreach ($now->keys()->diff($before) as $zoneId) {
            // Two points arriving at once must not give two "entered".
            $new = DB::table('geo_zone_presences')->insertOrIgnore([
                'geo_zone_id' => $zoneId, 'mover_type' => $moverType, 'mover_id' => $moverId, 'entered_at' => $at,
            ]);
            if ($new > 0) {
                $crossings[] = $this->crossing($now[$zoneId], $moverType, $moverId, GeoZoneCrossing::ENTER, $latitude, $longitude, $at, $about);
            }
        }
        foreach (array_diff($before, $now->keys()->all()) as $zoneId) {
            $gone = DB::table('geo_zone_presences')->where('geo_zone_id', $zoneId)->where('mover_type', $moverType)->where('mover_id', $moverId)->delete();
            $zone = GeoZone::query()->find($zoneId);
            if ($gone > 0 && $zone !== null) {
                $crossings[] = $this->crossing($zone, $moverType, $moverId, GeoZoneCrossing::EXIT, $latitude, $longitude, $at, $about);
            }
        }

        return $crossings;
    }

    /**
     * The mover is not watched any more (sharing stopped): they are nowhere, and that is not "left".
     */
    public function lost(string $moverType, int $moverId): void
    {
        DB::table('geo_zone_presences')->where('mover_type', $moverType)->where('mover_id', $moverId)->delete();
    }

    /**
     * @param  array<string, mixed>  $about
     */
    private function crossing(GeoZone $zone, string $moverType, int $moverId, string $direction, float $latitude, float $longitude, Carbon $at, array $about): GeoZoneCrossing
    {
        $crossing = GeoZoneCrossing::query()->create([
            'geo_zone_id' => $zone->id, 'mover_type' => $moverType, 'mover_id' => $moverId, 'direction' => $direction,
            'occurred_at' => $at, 'latitude' => $latitude, 'longitude' => $longitude,
        ]);
        GeoZoneCrossed::dispatch($crossing);

        if ($zone->notify_crossings) {
            $zone->responsibles()->with('user')->get()
                ->filter(fn (Person $person): bool => $person->user !== null && ! ($moverType === GeoZoneCrossing::PERSON && $person->id === $moverId))
                ->each(fn (Person $person) => $person->user->notify(new FieldNotice(FieldNotice::ZONE_CROSSING, [
                    ...$about, 'mover_type' => $moverType, 'mover_id' => $moverId, 'zone' => $zone->name,
                    'direction' => $direction, 'body' => $at->isoFormat('LLL'), 'url' => '/admin/geo-zones/'.$zone->id,
                ])));
        }

        return $crossing;
    }
}
