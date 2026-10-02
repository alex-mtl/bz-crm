<?php

declare(strict_types=1);

namespace App\Domain\Access\Scopes;

use App\Domain\Geo\Models\Territory;
use App\Domain\Organization\Models\OrgUnit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * For objects that stand in one territory and belong to no unit (a house, a geozone). On the unit axis such an
 * object belongs to the units that work in its territory — those bound to it or to a territory above it.
 * An object without a territory has no place: only an organization-wide scope covers it.
 *
 * Implements ScopeLocator.
 */
final readonly class TerritoryColumnLocator implements ScopeLocator
{
    public function __construct(private string $territoryColumn = 'territory_id') {}

    public function unitPaths(object $object): array
    {
        $territory = $this->territory($object);
        if ($territory === null) {
            return [];
        }

        return OrgUnit::query()->join('org_unit_territories', 'org_unit_territories.org_unit_id', '=', 'org_units.id')
            ->whereIn('org_unit_territories.territory_id', [...$territory->ancestorIds(), $territory->id])
            ->pluck('org_units.path')->map(fn ($path): string => (string) $path)->unique()->values()->all();
    }

    public function territoryPaths(object $object): array
    {
        $territory = $this->territory($object);

        return $territory !== null && $territory->path !== '' ? [$territory->path] : [];
    }

    public function whereInUnits(Builder $query, array $unitPaths): void
    {
        // The territories the units of the scope work in; the object must stand inside one of them.
        $territoryPaths = Territory::query()
            ->join('org_unit_territories', 'org_unit_territories.territory_id', '=', 'territories.id')
            ->join('org_units', 'org_units.id', '=', 'org_unit_territories.org_unit_id')
            ->where(fn (Builder $where) => PersonLocator::likeAny($where->getQuery(), 'org_units.path', $unitPaths))
            ->pluck('territories.path')->map(fn ($path): string => (string) $path)->unique()->values()->all();

        if ($territoryPaths === []) {
            $query->whereRaw('1 = 0');

            return;
        }
        $this->whereInTerritories($query, $territoryPaths);
    }

    public function whereInTerritories(Builder $query, array $territoryPaths): void
    {
        $query->whereIn($query->getModel()->qualifyColumn($this->territoryColumn), fn (QueryBuilder $sub) => $sub
            ->select('id')->from('territories')
            ->where(fn (QueryBuilder $where) => PersonLocator::likeAny($where, 'path', $territoryPaths)));
    }

    private function territory(object $object): ?Territory
    {
        $id = $object instanceof Model ? $object->getAttribute($this->territoryColumn) : null;

        return $id !== null ? Territory::query()->find($id, ['id', 'path']) : null;
    }
}
