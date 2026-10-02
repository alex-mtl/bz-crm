<?php

declare(strict_types=1);

namespace App\Domain\Geo;

use App\Domain\Catalogs\Models\CatalogItem;
use App\Domain\Geo\Models\GeoZone;
use App\Domain\Geo\Models\House;
use App\Domain\Geo\Models\Territory;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The figures of the canvass (ФО §6.11: «% обойдённых квартир, % сторонников»). Every figure is counted over
 * the houses the reader's right reaches — a head of one district never gets the numbers of the next one, not
 * even inside a total. What counts as "visited", "contact" and "supporter" is written in the statuses catalog.
 *
 * @phpstan-type Figures array{houses: int, apartments: int, visited: int, contacts: int, supporters: int, attempts: int, visited_pct: int, supporter_pct: int, by_status: array<string, int>}
 */
final class CanvassSummary
{
    /** @var array<string, array{visited: bool, contact: bool, supporter: bool}>|null */
    private ?array $statuses = null;

    public function __construct(
        private readonly FieldAccess $access,
        private readonly GeoService $geo,
    ) {}

    /**
     * @return Figures
     */
    public function forHouse(House $house): array
    {
        return $this->figures(House::query()->whereKey($house->id));
    }

    /**
     * @param  Builder<House>  $houses
     * @return Figures
     */
    public function figures(Builder $houses): array
    {
        $rows = $this->rows($houses, 'apartments.status_code');
        $figures = self::empty();
        $figures['houses'] = (clone $houses)->count();
        foreach ($rows as $row) {
            $this->add($figures, (string) $row->status_code, (int) $row->flats, (int) $row->attempts);
        }

        return self::withShares($figures);
    }

    /**
     * Rolled up along the territory tree: a sector holds the figures of its polling districts. Only the houses
     * the reader's `geo.summary.read` reaches are counted.
     *
     * @return list<array{territory: Territory, depth: int, figures: Figures}>
     */
    public function byTerritory(User $reader, ?Territory $within = null): array
    {
        $houses = $this->access->houses($reader, 'geo.summary.read')->active();
        if ($within !== null) {
            $houses->whereIn('houses.territory_id', Territory::query()->withinPath($within->path)->select('id'));
        }

        $perTerritory = [];
        foreach ($this->rows($houses, 'houses.territory_id', 'apartments.status_code') as $row) {
            $perTerritory[(int) $row->territory_id] ??= self::empty();
            $this->add($perTerritory[(int) $row->territory_id], (string) $row->status_code, (int) $row->flats, (int) $row->attempts);
        }
        foreach ((clone $houses)->reorder()->groupBy('houses.territory_id')->select('houses.territory_id', DB::raw('count(*) as total'))->toBase()->get() as $row) {
            $perTerritory[(int) $row->territory_id] ??= self::empty();
            $perTerritory[(int) $row->territory_id]['houses'] = (int) $row->total;
        }
        if ($perTerritory === []) {
            return [];
        }

        $own = Territory::query()->whereKey(array_keys($perTerritory))->get()->keyBy('id');
        $ancestorIds = $own->flatMap(fn (Territory $territory): array => $territory->ancestorIds())->unique()->all();
        $nodes = $own->union(Territory::query()->whereKey($ancestorIds)->get()->keyBy('id'));

        $rolled = [];
        foreach ($perTerritory as $territoryId => $figures) {
            $territory = $own[$territoryId] ?? null;
            if ($territory === null) {
                continue;
            }
            foreach ([...$territory->ancestorIds(), $territoryId] as $nodeId) {
                $rolled[$nodeId] ??= self::empty();
                self::merge($rolled[$nodeId], $figures);
            }
        }

        $minDepth = $within !== null ? $within->depth : 0;

        return $nodes->filter(fn (Territory $node): bool => isset($rolled[$node->id]) && $node->depth >= $minDepth)
            ->sortBy('path')->values()
            ->map(fn (Territory $node): array => ['territory' => $node, 'depth' => $node->depth - $minDepth, 'figures' => self::withShares($rolled[$node->id])])
            ->all();
    }

    /**
     * ФО §6.11: «все обходы внутри полигона … попадают в сводку» — the houses whose point lies inside the outline,
     * among those the reader's right reaches.
     *
     * @return Figures
     */
    public function forZone(User $reader, GeoZone $zone): array
    {
        $inside = $this->access->houses($reader, 'geo.summary.read')->active()
            ->whereNotNull('houses.latitude')
            ->whereBetween('houses.latitude', [$zone->min_latitude, $zone->max_latitude])
            ->whereBetween('houses.longitude', [$zone->min_longitude, $zone->max_longitude])
            ->get(['houses.id', 'houses.latitude', 'houses.longitude'])
            ->filter(fn (House $house): bool => $this->geo->zoneContains($zone, (float) $house->latitude, (float) $house->longitude))
            ->pluck('id')->all();

        return $this->figures(House::query()->whereKey($inside));
    }

    /**
     * Per house, for a list or the map: house id => figures.
     *
     * @param  Builder<House>  $houses
     * @return array<int, Figures>
     */
    public function perHouse(Builder $houses): array
    {
        $result = [];
        foreach ($this->rows($houses, 'houses.id', 'apartments.status_code') as $row) {
            $result[(int) $row->id] ??= self::empty();
            $this->add($result[(int) $row->id], (string) $row->status_code, (int) $row->flats, (int) $row->attempts);
        }

        return array_map(fn (array $figures): array => self::withShares([...$figures, 'houses' => 1]), $result);
    }

    /**
     * @param  Builder<House>  $houses
     * @return Collection<int, \stdClass>
     */
    private function rows(Builder $houses, string ...$groupBy)
    {
        return (clone $houses)->reorder()
            ->join('apartments', 'apartments.house_id', '=', 'houses.id')
            ->groupBy(...$groupBy)
            ->select([...$groupBy, DB::raw('count(*) as flats'), DB::raw('sum(apartments.attempts) as attempts')])
            ->toBase()->get();
    }

    /**
     * @param  Figures  $figures
     */
    private function add(array &$figures, string $status, int $flats, int $attempts): void
    {
        $this->statuses ??= CatalogItem::query()->ofCatalog('canvass_statuses')->get()->mapWithKeys(fn (CatalogItem $item): array => [$item->code => [
            'visited' => (bool) $item->property('visited'), 'contact' => (bool) $item->property('contact'), 'supporter' => (bool) $item->property('supporter'),
        ]])->all();
        $kind = $this->statuses[$status] ?? ['visited' => false, 'contact' => false, 'supporter' => false];

        $figures['apartments'] += $flats;
        $figures['attempts'] += $attempts;
        $figures['visited'] += $kind['visited'] ? $flats : 0;
        $figures['contacts'] += $kind['contact'] ? $flats : 0;
        $figures['supporters'] += $kind['supporter'] ? $flats : 0;
        $figures['by_status'][$status] = ($figures['by_status'][$status] ?? 0) + $flats;
    }

    /**
     * @param  Figures  $into
     * @param  Figures  $from
     */
    private static function merge(array &$into, array $from): void
    {
        foreach (['houses', 'apartments', 'visited', 'contacts', 'supporters', 'attempts'] as $key) {
            $into[$key] += $from[$key];
        }
        foreach ($from['by_status'] as $status => $count) {
            $into['by_status'][$status] = ($into['by_status'][$status] ?? 0) + $count;
        }
    }

    /**
     * @param  Figures  $figures
     * @return Figures
     */
    private static function withShares(array $figures): array
    {
        $figures['visited_pct'] = $figures['apartments'] > 0 ? (int) round($figures['visited'] * 100 / $figures['apartments']) : 0;
        $figures['supporter_pct'] = $figures['apartments'] > 0 ? (int) round($figures['supporters'] * 100 / $figures['apartments']) : 0;

        return $figures;
    }

    /**
     * @return Figures
     */
    private static function empty(): array
    {
        return ['houses' => 0, 'apartments' => 0, 'visited' => 0, 'contacts' => 0, 'supporters' => 0, 'attempts' => 0, 'visited_pct' => 0, 'supporter_pct' => 0, 'by_status' => []];
    }
}
