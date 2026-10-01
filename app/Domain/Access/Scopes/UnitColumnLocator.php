<?php

declare(strict_types=1);

namespace App\Domain\Access\Scopes;

use App\Domain\Geo\Models\Territory;
use App\Domain\Organization\Models\OrgUnit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * For objects that store their place directly: an owning unit column and, optionally, a territory column
 * (projects, tasks). Territorially the object lives in its own territory if set, otherwise in its unit's territories.
 *
 * Implements ScopeLocator.
 */
final readonly class UnitColumnLocator implements ScopeLocator
{
    public function __construct(private string $unitColumn, private ?string $territoryColumn = null) {}

    public function unitPaths(object $object): array
    {
        $unitId = $object instanceof Model ? $object->getAttribute($this->unitColumn) : null;
        $path = $unitId !== null ? OrgUnit::query()->whereKey($unitId)->value('path') : null;

        return $path !== null ? [(string) $path] : [];
    }

    public function territoryPaths(object $object): array
    {
        if (! $object instanceof Model) {
            return [];
        }
        $territoryId = $this->territoryColumn !== null ? $object->getAttribute($this->territoryColumn) : null;
        if ($territoryId !== null) {
            return array_filter([(string) Territory::query()->whereKey($territoryId)->value('path')]);
        }
        $unitId = $object->getAttribute($this->unitColumn);
        if ($unitId === null) {
            return [];
        }

        return Territory::query()->join('org_unit_territories', 'org_unit_territories.territory_id', '=', 'territories.id')
            ->where('org_unit_territories.org_unit_id', $unitId)->pluck('territories.path')->map(fn ($p): string => (string) $p)->all();
    }

    public function whereInUnits(Builder $query, array $unitPaths): void
    {
        $query->whereIn($query->getModel()->qualifyColumn($this->unitColumn), fn (QueryBuilder $sub) => $sub
            ->select('id')->from('org_units')
            ->where(fn (QueryBuilder $w) => PersonLocator::likeAny($w, 'path', $unitPaths)));
    }

    public function whereInTerritories(Builder $query, array $territoryPaths): void
    {
        $unitColumn = $query->getModel()->qualifyColumn($this->unitColumn);
        $territoryColumn = $this->territoryColumn !== null ? $query->getModel()->qualifyColumn($this->territoryColumn) : null;

        $query->where(function (Builder $where) use ($unitColumn, $territoryColumn, $territoryPaths): void {
            if ($territoryColumn !== null) {
                $where->whereIn($territoryColumn, fn (QueryBuilder $sub) => $sub->select('id')->from('territories')
                    ->where(fn (QueryBuilder $w) => PersonLocator::likeAny($w, 'path', $territoryPaths)));
            }
            $where->orWhere(function (Builder $byUnit) use ($unitColumn, $territoryColumn, $territoryPaths): void {
                if ($territoryColumn !== null) {
                    $byUnit->whereNull($territoryColumn);
                }
                $byUnit->whereExists(fn (QueryBuilder $sub) => $sub->selectRaw('1')
                    ->from('org_unit_territories as scope_ut')
                    ->join('territories as scope_t', 'scope_t.id', '=', 'scope_ut.territory_id')
                    ->whereColumn('scope_ut.org_unit_id', $unitColumn)
                    ->where(fn (QueryBuilder $w) => PersonLocator::likeAny($w, 'scope_t.path', $territoryPaths)));
            });
        });
    }
}
