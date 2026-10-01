<?php

declare(strict_types=1);

namespace App\Domain\Access\Scopes;

use App\Domain\Geo\Models\Territory;
use App\Domain\Organization\Models\OrgUnit;
use App\Domain\Organization\OrgStructure;
use App\Domain\People\Models\Person;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * A person (or anything tied to one person, e.g. a User) lives in their unit and in that unit's
 * territories — "регион владельца" = exactly the unit's territories (Д-13); direct grants do not move them.
 *
 * A person outside the org structure — a supporter, a partner, a candidate (ФО §6.9.1) — lives in the territory
 * written on the card and belongs to the card's responsible unit; without a territory, to that unit's territories.
 *
 * Implements ScopeLocator.
 */
final readonly class PersonLocator implements ScopeLocator
{
    /**
     * @param  Closure(object): ?int  $personId  person id of the object
     * @param  string  $personColumn  column holding the person id in the object's table
     */
    public function __construct(private OrgStructure $org, private Closure $personId, private string $personColumn) {}

    public function unitPaths(object $object): array
    {
        $id = ($this->personId)($object);
        $unit = $id !== null ? $this->org->unitOf($id) : null;
        if ($unit !== null) {
            return [$unit->path];
        }

        $unitId = $this->card($object, $id)?->responsible_unit_id;
        $path = $unitId !== null ? OrgUnit::query()->whereKey($unitId)->value('path') : null;

        return $path !== null ? [(string) $path] : [];
    }

    public function territoryPaths(object $object): array
    {
        $id = ($this->personId)($object);
        $unit = $id !== null ? $this->org->unitOf($id) : null;
        if ($unit !== null) {
            return $unit->territories()->pluck('path')->map(fn ($p): string => (string) $p)->all();
        }

        $card = $this->card($object, $id);
        if ($card?->territory_id !== null) {
            return array_filter([(string) Territory::query()->whereKey($card->territory_id)->value('path')]);
        }
        if ($card?->responsible_unit_id === null) {
            return [];
        }

        return Territory::query()->join('org_unit_territories', 'org_unit_territories.territory_id', '=', 'territories.id')
            ->where('org_unit_territories.org_unit_id', $card->responsible_unit_id)
            ->pluck('territories.path')->map(fn ($p): string => (string) $p)->all();
    }

    public function whereInUnits(Builder $query, array $unitPaths): void
    {
        $column = $query->getModel()->qualifyColumn($this->personColumn);
        $query->where(fn (Builder $where) => $where
            ->whereExists(fn (QueryBuilder $sub) => $sub->selectRaw('1')
                ->from('org_memberships as scope_m')
                ->join('org_units as scope_u', 'scope_u.id', '=', 'scope_m.org_unit_id')
                ->whereColumn('scope_m.person_id', $column)
                ->where(fn (QueryBuilder $w) => self::likeAny($w, 'scope_u.path', $unitPaths)))
            ->orWhereIn($column, fn (QueryBuilder $sub) => self::outsideStructure($sub)
                ->whereIn('scope_p.responsible_unit_id', fn (QueryBuilder $units) => $units->select('id')->from('org_units')
                    ->where(fn (QueryBuilder $w) => self::likeAny($w, 'path', $unitPaths)))));
    }

    public function whereInTerritories(Builder $query, array $territoryPaths): void
    {
        $column = $query->getModel()->qualifyColumn($this->personColumn);
        $query->where(fn (Builder $where) => $where
            ->whereExists(fn (QueryBuilder $sub) => $sub->selectRaw('1')
                ->from('org_memberships as scope_m')
                ->join('org_unit_territories as scope_ut', 'scope_ut.org_unit_id', '=', 'scope_m.org_unit_id')
                ->join('territories as scope_t', 'scope_t.id', '=', 'scope_ut.territory_id')
                ->whereColumn('scope_m.person_id', $column)
                ->where(fn (QueryBuilder $w) => self::likeAny($w, 'scope_t.path', $territoryPaths)))
            ->orWhereIn($column, fn (QueryBuilder $sub) => self::outsideStructure($sub)->where(fn (QueryBuilder $place) => $place
                ->whereIn('scope_p.territory_id', fn (QueryBuilder $t) => $t->select('id')->from('territories')
                    ->where(fn (QueryBuilder $w) => self::likeAny($w, 'path', $territoryPaths)))
                ->orWhere(fn (QueryBuilder $byUnit) => $byUnit->whereNull('scope_p.territory_id')
                    ->whereExists(fn (QueryBuilder $ut) => $ut->selectRaw('1')
                        ->from('org_unit_territories as scope_put')
                        ->join('territories as scope_pt', 'scope_pt.id', '=', 'scope_put.territory_id')
                        ->whereColumn('scope_put.org_unit_id', 'scope_p.responsible_unit_id')
                        ->where(fn (QueryBuilder $w) => self::likeAny($w, 'scope_pt.path', $territoryPaths)))))));
    }

    /**
     * @param  list<string>  $paths
     */
    public static function likeAny(QueryBuilder $where, string $column, array $paths): void
    {
        foreach ($paths as $path) {
            $where->orWhere($column, 'like', $path.'%');
        }
    }

    /**
     * Ids of the cards that have no place in the org structure.
     */
    private static function outsideStructure(QueryBuilder $sub): QueryBuilder
    {
        return $sub->select('scope_p.id')->from('people as scope_p')
            ->whereNotExists(fn (QueryBuilder $m) => $m->selectRaw('1')->from('org_memberships as scope_pm')
                ->whereColumn('scope_pm.person_id', 'scope_p.id'));
    }

    /**
     * The card itself: the object when it is a (possibly unsaved) Person, otherwise loaded by id.
     */
    private function card(object $object, ?int $id): ?Person
    {
        if ($object instanceof Person) {
            return $object;
        }

        return $id !== null ? Person::query()->find($id, ['id', 'territory_id', 'responsible_unit_id']) : null;
    }
}
