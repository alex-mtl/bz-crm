<?php

declare(strict_types=1);

namespace App\Domain\Access\Scopes;

use App\Domain\Geo\Models\Territory;
use App\Domain\Organization\Models\OrgUnit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * The tree nodes themselves: a unit lives in its own path (and in its territories); a territory in its path.
 * An unsaved node has no place — only an organization-wide scope covers it (used for "create a root").
 *
 * Implements ScopeLocator.
 */
final class TreeNodeLocator implements ScopeLocator
{
    public function unitPaths(object $object): array
    {
        return $object instanceof OrgUnit && $object->exists ? [$object->path] : [];
    }

    public function territoryPaths(object $object): array
    {
        return match (true) {
            $object instanceof Territory && $object->exists => [$object->path],
            $object instanceof OrgUnit && $object->exists => $object->territories()->pluck('path')->map(fn ($p): string => (string) $p)->all(),
            default => [],
        };
    }

    public function whereInUnits(Builder $query, array $unitPaths): void
    {
        if ($query->getModel() instanceof OrgUnit) {
            $query->where(fn (Builder $w) => PersonLocator::likeAny($w->getQuery(), $query->getModel()->qualifyColumn('path'), $unitPaths));
        } else {
            $query->whereRaw('1 = 0');
        }
    }

    public function whereInTerritories(Builder $query, array $territoryPaths): void
    {
        $model = $query->getModel();
        if ($model instanceof Territory) {
            $query->where(fn (Builder $w) => PersonLocator::likeAny($w->getQuery(), $model->qualifyColumn('path'), $territoryPaths));

            return;
        }
        $query->whereExists(fn (QueryBuilder $sub) => $sub->selectRaw('1')
            ->from('org_unit_territories as scope_ut')
            ->join('territories as scope_t', 'scope_t.id', '=', 'scope_ut.territory_id')
            ->whereColumn('scope_ut.org_unit_id', $model->qualifyColumn('id'))
            ->where(fn (QueryBuilder $w) => PersonLocator::likeAny($w, 'scope_t.path', $territoryPaths)));
    }
}
