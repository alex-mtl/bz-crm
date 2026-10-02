<?php

declare(strict_types=1);

namespace App\Domain\Geo;

use App\Domain\Access\AuthorizationService;
use App\Domain\Access\Scopes\PersonLocator;
use App\Domain\Geo\Models\FieldAssignment;
use App\Domain\Geo\Models\House;
use App\Domain\Geo\Models\Territory;
use App\Domain\Identity\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Who works with which house (ФО §6.11, catalog §9). Every right of the field work is asked about a house:
 * a head — by the territory of the house in the scope of their role; an agitator — by the houses they answer for,
 * given one by one or by a whole territory. Screens, the API and the offline queue all ask here.
 */
final class FieldAccess
{
    /** @var array<int, list<string>> person id => paths of the territories assigned to them */
    private array $territoryPaths = [];

    public function __construct(private readonly AuthorizationService $authorization) {}

    /**
     * The houses the code reaches for this person — in SQL, for lists, counters and summaries.
     *
     * @return Builder<House>
     */
    public function houses(User $user, string $code = 'geo.houses.read'): Builder
    {
        return $this->authorization->scopeQuery($user, $code, House::query());
    }

    public function can(User $user, string $code, House $house): bool
    {
        return $this->authorization->can($user, $code, $house);
    }

    /**
     * @throws AuthorizationException
     */
    public function authorize(User $user, string $code, House $house): void
    {
        $this->authorization->authorize($user, $code, $house);
    }

    /**
     * The houses a person answers for now — "мои дома" of the agitator at the entrance.
     *
     * @return Builder<House>
     */
    public function assignedHouses(int $personId): Builder
    {
        $query = House::query()->active();
        $this->whereAssigned($query, $personId);

        return $query;
    }

    public function isAssigned(int $personId, House $house): bool
    {
        if (! $house->exists) {
            return false;
        }
        if (FieldAssignment::query()->current()->where('person_id', $personId)->where('house_id', $house->id)->exists()) {
            return true;
        }
        $path = (string) Territory::query()->whereKey($house->territory_id)->value('path');
        foreach ($this->assignedTerritoryPaths($personId) as $assigned) {
            if ($path !== '' && str_starts_with($path, $assigned)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The same as isAssigned(), in SQL.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query  over houses
     */
    public function whereAssigned(Builder $query, int $personId): void
    {
        $model = $query->getModel();
        $paths = $this->assignedTerritoryPaths($personId);

        $query->where(function (Builder $where) use ($model, $personId, $paths): void {
            $where->whereIn($model->qualifyColumn('id'), fn (QueryBuilder $sub) => $sub->select('house_id')->from('field_assignments')
                ->where('person_id', $personId)->whereNull('ended_at')->whereNotNull('house_id'));
            if ($paths !== []) {
                $where->orWhereIn($model->qualifyColumn('territory_id'), fn (QueryBuilder $sub) => $sub->select('id')->from('territories')
                    ->where(fn (QueryBuilder $inside) => PersonLocator::likeAny($inside, 'path', $paths)));
            }
        });
    }

    public function forget(): void
    {
        $this->territoryPaths = [];
    }

    /**
     * @return list<string>
     */
    private function assignedTerritoryPaths(int $personId): array
    {
        return $this->territoryPaths[$personId] ??= Territory::query()
            ->whereIn('id', FieldAssignment::query()->current()->where('person_id', $personId)->whereNotNull('territory_id')->select('territory_id'))
            ->pluck('path')->map(fn ($path): string => (string) $path)->filter()->values()->all();
    }
}
