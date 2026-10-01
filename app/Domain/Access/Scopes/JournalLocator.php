<?php

declare(strict_types=1);

namespace App\Domain\Access\Scopes;

use App\Domain\Audit\Models\JournalEntry;
use App\Domain\Identity\Models\User;
use App\Domain\People\Models\Person;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Journal entries live where their subject lives (plan 2c): an event about a person or an account belongs to
 * the person's unit and its territories. A scoped journal reader (e.g. a region head) sees only those.
 *
 * Implements ScopeLocator.
 */
final readonly class JournalLocator implements ScopeLocator
{
    public function __construct(private PersonLocator $people) {}

    public function unitPaths(object $object): array
    {
        $person = $this->personOf($object);

        return $person !== null ? $this->people->unitPaths($person) : [];
    }

    public function territoryPaths(object $object): array
    {
        $person = $this->personOf($object);

        return $person !== null ? $this->people->territoryPaths($person) : [];
    }

    public function whereInUnits(Builder $query, array $unitPaths): void
    {
        $this->wherePersonIn($query, fn (QueryBuilder $members) => $members
            ->join('org_units as scope_u', 'scope_u.id', '=', 'scope_m.org_unit_id')
            ->where(fn (QueryBuilder $w) => PersonLocator::likeAny($w, 'scope_u.path', $unitPaths)));
    }

    public function whereInTerritories(Builder $query, array $territoryPaths): void
    {
        $this->wherePersonIn($query, fn (QueryBuilder $members) => $members
            ->join('org_unit_territories as scope_ut', 'scope_ut.org_unit_id', '=', 'scope_m.org_unit_id')
            ->join('territories as scope_t', 'scope_t.id', '=', 'scope_ut.territory_id')
            ->where(fn (QueryBuilder $w) => PersonLocator::likeAny($w, 'scope_t.path', $territoryPaths)));
    }

    /**
     * @param  Builder<covariant Model>  $query
     * @param  \Closure(QueryBuilder): mixed  $membersFilter
     */
    private function wherePersonIn(Builder $query, \Closure $membersFilter): void
    {
        $persons = function (QueryBuilder $sub) use ($membersFilter): void {
            $sub->select('scope_m.person_id')->from('org_memberships as scope_m');
            $membersFilter($sub);
        };
        $query->where(fn (Builder $w) => $w
            ->where(fn (Builder $p) => $p->where('subject_type', (new Person)->getMorphClass())
                ->whereIn('subject_id', $persons))
            ->orWhere(fn (Builder $u) => $u->where('subject_type', (new User)->getMorphClass())
                ->whereIn('subject_id', fn (QueryBuilder $users) => $users->select('id')->from('users')->whereIn('person_id', $persons))));
    }

    private function personOf(object $object): ?Person
    {
        if (! $object instanceof JournalEntry || $object->subject_id === null) {
            return null;
        }

        return match ($object->subject_type) {
            (new Person)->getMorphClass() => Person::query()->find((int) $object->subject_id),
            (new User)->getMorphClass() => User::query()->find((int) $object->subject_id)?->person,
            default => null,
        };
    }
}
