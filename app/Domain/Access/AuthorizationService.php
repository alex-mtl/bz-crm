<?php

declare(strict_types=1);

namespace App\Domain\Access;

use App\Domain\Access\Enums\PermissionEffect;
use App\Domain\Access\Models\Role;
use App\Domain\Access\Models\RolePermission;
use App\Domain\Access\Models\UserRole;
use App\Domain\Access\Scopes\ScopeLocator;
use App\Domain\Access\Scopes\ScopeType;
use App\Domain\Geo\Models\Territory;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\OrgUnit;
use App\Domain\Organization\OrgStructure;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The single place where access is decided (ТЗ §15, ADR-003, ADR-008, Д-17):
 *
 *   a role in effect grants the code (deny from any role wins)
 *   ∧ the object is in the assignment's scope — organization / unit subtree / territory subtree /
 *     own unit / own territories (Д-3) — or a relation rule links the user to it ("own", "related")
 *   ∧ object rules pass (data layer)
 *   ∧ no hard constraint vetoes it.
 *
 * Some relations grant a code by themselves, without any role — e.g. the direct manager (Д-11).
 * For lists, the same decision is applied in SQL by scopeQuery().
 */
final class AuthorizationService
{
    public const string RELATION_OWN = 'own';

    public const string RELATION_RELATED = 'related';

    /** the relation grants the code even without a role */
    public const string RELATION_GRANT = 'grant';

    /** @var array<int, array{deny: array<string, true>, grants: array<string, list<array{scope: ScopeType, scope_id: int|null, data: string|null}>>}> */
    private array $effective = [];

    /** @var array<class-string, ScopeLocator> */
    private array $locators = [];

    /** @var array<string, list<array{kind: string, check: Closure(User, object): bool, query: (Closure(User, Builder<covariant Model>): mixed)|null}>> */
    private array $relations = [];

    /** @var array<string, list<Closure(User, object): bool>> */
    private array $objectRules = [];

    /** @var array<string, Closure(User, Builder<covariant Model>): mixed> */
    private array $queryScopes = [];

    /** @var list<Closure(User, string, object|null): bool> each returns false to veto */
    private array $hardConstraints = [];

    /** @var array<string, string> "unit:5" / "territory:7" => path */
    private array $paths = [];

    public function __construct(
        private readonly PermissionRegistry $registry,
        private readonly OrgStructure $org,
        private readonly TerritorialAccess $territories,
    ) {}

    public function can(User $user, string $code, ?object $subject = null): bool
    {
        if (! $this->registry->has($code) || ! $user->isActive()) {
            return false;
        }

        foreach ($this->hardConstraints as $constraint) {
            if ($constraint($user, $code, $subject) === false) {
                return false;
            }
        }

        $effective = $this->effective($user);
        if (isset($effective['deny'][$code])) {
            return false;
        }
        $grants = $effective['grants'][$code] ?? [];

        if ($subject === null) {
            return $grants !== [];
        }

        if (! $this->grantedFor($user, $code, $grants, $subject)) {
            return false;
        }

        foreach ($this->objectRules[$code] ?? [] as $rule) {
            if (! $rule($user, $subject)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @throws AuthorizationException
     */
    public function authorize(User $user, string $code, ?object $subject = null): void
    {
        if (! $this->can($user, $code, $subject)) {
            throw new AuthorizationException(__('access.denied'));
        }
    }

    /**
     * Restricts a list query to the objects the user may see under this code — in SQL, so search,
     * exports and counters never reveal what the user may not see (ТЗ §52, §67).
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function scopeQuery(User $user, string $code, Builder $query): Builder
    {
        if (! $this->registry->has($code) || ! $user->isActive()) {
            return $query->whereRaw('1 = 0');
        }
        $effective = $this->effective($user);
        if (isset($effective['deny'][$code])) {
            return $query->whereRaw('1 = 0');
        }

        $unitPaths = [];
        $territoryPaths = [];
        $kinds = [self::RELATION_GRANT => true];
        foreach ($effective['grants'][$code] ?? [] as $grant) {
            foreach ($this->relationKinds($grant['data']) as $kind) {
                $kinds[$kind] = true;
            }
            if ($grant['data'] !== null) {
                continue;
            }
            switch ($grant['scope']) {
                case ScopeType::Organization:
                    $this->applyQueryScope($user, $code, $query);

                    return $query;
                case ScopeType::OrgUnit:
                case ScopeType::OwnUnit:
                    $unitPaths = [...$unitPaths, ...$this->grantUnitPaths($user, $grant)];
                    break;
                case ScopeType::Territory:
                case ScopeType::OwnTerritories:
                    $territoryPaths = [...$territoryPaths, ...$this->grantTerritoryPaths($user, $grant)];
                    break;
            }
        }

        $locator = $this->locators[$query->getModel()::class] ?? null;
        $relations = array_values(array_filter(
            $this->relations[$code] ?? [],
            fn (array $relation): bool => isset($kinds[$relation['kind']]) && $relation['query'] !== null,
        ));

        $query->where(function (Builder $where) use ($user, $locator, $unitPaths, $territoryPaths, $relations): void {
            $any = false;
            if ($locator !== null && $unitPaths !== []) {
                $where->orWhere(fn (Builder $q) => $locator->whereInUnits($q, array_values(array_unique($unitPaths))));
                $any = true;
            }
            if ($locator !== null && $territoryPaths !== []) {
                $where->orWhere(fn (Builder $q) => $locator->whereInTerritories($q, array_values(array_unique($territoryPaths))));
                $any = true;
            }
            foreach ($relations as $relation) {
                $where->orWhere(fn (Builder $q) => ($relation['query'])($user, $q));
                $any = true;
            }
            if (! $any) {
                $where->whereRaw('1 = 0');
            }
        });
        $this->applyQueryScope($user, $code, $query);

        return $query;
    }

    /**
     * Codes the user holds in some scope (deny already applied). Used by the "not more than you have" rule.
     *
     * @return list<string>
     */
    public function allowedCodes(User $user): array
    {
        if (! $user->isActive()) {
            return [];
        }
        $effective = $this->effective($user);

        return array_values(array_filter(
            array_keys($effective['grants']),
            fn (string $code): bool => ! isset($effective['deny'][$code]) && $this->registry->has($code),
        ));
    }

    /**
     * @return list<array{scope: ScopeType, scope_id: int|null, data: string|null}>
     */
    public function grantsFor(User $user, string $code): array
    {
        if (! $user->isActive()) {
            return [];
        }
        $effective = $this->effective($user);

        return isset($effective['deny'][$code]) ? [] : ($effective['grants'][$code] ?? []);
    }

    /**
     * Does one of the user's grants of this code cover the given assignment scope? ("Not more than you have", Д-17.)
     * Only an organization-wide grant covers an organization-wide scope; a unit grant covers units inside it;
     * a territory grant covers territories inside it. Scopes of different axes do not cover each other.
     */
    public function coversScope(User $actor, string $code, ScopeType $scope, ?int $scopeId, ?User $holder = null): bool
    {
        $target = match ($scope) {
            ScopeType::Organization => null,
            ScopeType::OrgUnit => ['units', [$this->unitPath((int) $scopeId)]],
            ScopeType::OwnUnit => ['units', $holder !== null ? $this->ownUnitPaths($holder) : []],
            ScopeType::Territory => ['territories', [$this->territoryPath((int) $scopeId)]],
            ScopeType::OwnTerritories => ['territories', $holder !== null ? $this->territories->paths($holder->person_id) : []],
        };

        foreach ($this->grantsFor($actor, $code) as $grant) {
            if ($grant['data'] !== null) {
                continue;
            }
            if ($grant['scope'] === ScopeType::Organization) {
                return true;
            }
            if ($target === null || $target[1] === [] || in_array('', $target[1], true)) {
                continue;
            }
            $actorPaths = match ($grant['scope']) {
                ScopeType::OrgUnit, ScopeType::OwnUnit => $target[0] === 'units' ? $this->grantUnitPaths($actor, $grant) : [],
                default => $target[0] === 'territories' ? $this->grantTerritoryPaths($actor, $grant) : [],
            };
            $allInside = true;
            foreach ($target[1] as $path) {
                $allInside = $allInside && self::insideAny($path, $actorPaths);
            }
            if ($allInside) {
                return true;
            }
        }

        return false;
    }

    /**
     * Where objects of this class live on the scope axes.
     *
     * @param  class-string  $class
     */
    public function registerLocator(string $class, ScopeLocator $locator): void
    {
        $this->locators[$class] = $locator;
    }

    /**
     * A relation between the user and an object that counts for this code:
     *  - own / related: used by grants whose data layer is "own" / "related" (and by every grant of the code);
     *  - grant: gives the code by itself, without any role (e.g. the direct manager, Д-11).
     *
     * @param  Closure(User, object): bool  $check
     * @param  (Closure(User, Builder<covariant Model>): mixed)|null  $query  the same condition in SQL, for lists
     */
    public function addRelation(string $code, string $kind, Closure $check, ?Closure $query = null): void
    {
        $this->relations[$code][] = ['kind' => $kind, 'check' => $check, 'query' => $query];
    }

    /**
     * An extra condition the object must meet (AND), whatever grants the code.
     *
     * @param  Closure(User, object): bool  $rule
     */
    public function addObjectRule(string $code, Closure $rule): void
    {
        $this->objectRules[$code][] = $rule;
    }

    /**
     * An extra SQL condition for lists under this code (AND), e.g. hiding confidential journal events.
     *
     * @param  Closure(User, Builder<covariant Model>): mixed  $scope
     */
    public function setQueryScope(string $code, Closure $scope): void
    {
        $this->queryScopes[$code] = $scope;
    }

    /**
     * @param  Closure(User, string, object|null): bool  $constraint
     */
    public function addHardConstraint(Closure $constraint): void
    {
        $this->hardConstraints[] = $constraint;
    }

    public function forget(?User $user = null): void
    {
        if ($user === null) {
            $this->effective = [];
            $this->paths = [];
        } else {
            unset($this->effective[$user->id]);
        }
        $this->territories->forget();
        $this->org->forget();
    }

    /**
     * @param  list<array{scope: ScopeType, scope_id: int|null, data: string|null}>  $grants
     */
    private function grantedFor(User $user, string $code, array $grants, object $subject): bool
    {
        $kinds = [self::RELATION_GRANT => true];
        foreach ($grants as $grant) {
            if ($grant['data'] === null && $this->inScope($user, $grant, $subject)) {
                return true;
            }
            foreach ($this->relationKinds($grant['data']) as $kind) {
                $kinds[$kind] = true;
            }
        }

        foreach ($this->relations[$code] ?? [] as $relation) {
            if (isset($kinds[$relation['kind']]) && ($relation['check'])($user, $subject)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function relationKinds(?string $data): array
    {
        return $data === self::RELATION_OWN ? [self::RELATION_OWN] : [self::RELATION_OWN, self::RELATION_RELATED];
    }

    /**
     * @param  array{scope: ScopeType, scope_id: int|null, data: string|null}  $grant
     */
    private function inScope(User $user, array $grant, object $subject): bool
    {
        if ($grant['scope'] === ScopeType::Organization) {
            return true;
        }
        $locator = $this->locatorFor($subject);
        if ($locator === null) {
            return false;
        }

        return match ($grant['scope']) {
            ScopeType::OrgUnit, ScopeType::OwnUnit => self::anyInside($locator->unitPaths($subject), $this->grantUnitPaths($user, $grant)),
            default => self::anyInside($locator->territoryPaths($subject), $this->grantTerritoryPaths($user, $grant)),
        };
    }

    /**
     * @param  array{scope: ScopeType, scope_id: int|null, data: string|null}  $grant
     * @return list<string>
     */
    private function grantUnitPaths(User $user, array $grant): array
    {
        return $grant['scope'] === ScopeType::OwnUnit ? $this->ownUnitPaths($user) : array_filter([$this->unitPath((int) $grant['scope_id'])]);
    }

    /**
     * @param  array{scope: ScopeType, scope_id: int|null, data: string|null}  $grant
     * @return list<string>
     */
    private function grantTerritoryPaths(User $user, array $grant): array
    {
        return $grant['scope'] === ScopeType::OwnTerritories
            ? $this->territories->paths($user->person_id)
            : array_filter([$this->territoryPath((int) $grant['scope_id'])]);
    }

    /**
     * @return list<string>
     */
    private function ownUnitPaths(User $user): array
    {
        $unit = $this->org->unitOf($user->person_id);

        return $unit !== null ? [$unit->path] : [];
    }

    private function unitPath(int $id): string
    {
        return $this->paths['unit:'.$id] ??= (string) OrgUnit::query()->whereKey($id)->value('path');
    }

    private function territoryPath(int $id): string
    {
        return $this->paths['territory:'.$id] ??= (string) Territory::query()->whereKey($id)->value('path');
    }

    private function locatorFor(object $subject): ?ScopeLocator
    {
        foreach ($this->locators as $class => $locator) {
            if ($subject instanceof $class) {
                return $locator;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $objectPaths
     * @param  list<string>  $scopePaths
     */
    private static function anyInside(array $objectPaths, array $scopePaths): bool
    {
        foreach ($objectPaths as $path) {
            if (self::insideAny($path, $scopePaths)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $scopePaths
     */
    private static function insideAny(string $path, array $scopePaths): bool
    {
        foreach ($scopePaths as $scopePath) {
            if ($scopePath !== '' && $path !== '' && str_starts_with($path, $scopePath)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    private function applyQueryScope(User $user, string $code, Builder $query): void
    {
        if (isset($this->queryScopes[$code])) {
            ($this->queryScopes[$code])($user, $query);
        }
    }

    /**
     * Roles in effect (with inherited roles) → allowed grants per code with their scope and data layer; denies.
     *
     * @return array{deny: array<string, true>, grants: array<string, list<array{scope: ScopeType, scope_id: int|null, data: string|null}>>}
     */
    private function effective(User $user): array
    {
        if (isset($this->effective[$user->id])) {
            return $this->effective[$user->id];
        }

        $assignments = UserRole::query()->where('user_id', $user->id)->inEffect()->get(['role_id', 'scope_type', 'scope_id']);
        $parents = Role::query()->pluck('inherits_role_id', 'id');
        $chains = [];
        foreach ($assignments->pluck('role_id')->unique() as $roleId) {
            $chain = [];
            for ($id = (int) $roleId; $id !== 0 && ! in_array($id, $chain, true); $id = (int) ($parents[$id] ?? 0)) {
                $chain[] = $id;
            }
            $chains[(int) $roleId] = $chain;
        }
        $rows = RolePermission::query()->whereIn('role_id', array_merge([], ...array_values($chains)))
            ->get(['role_id', 'permission_code', 'effect', 'data_scope'])->groupBy('role_id');

        $result = ['deny' => [], 'grants' => []];
        foreach ($assignments as $assignment) {
            $scope = ScopeType::fromStored($assignment->scope_type);
            foreach ($chains[$assignment->role_id] ?? [] as $roleId) {
                foreach ($rows[$roleId] ?? [] as $row) {
                    if ($row->effect === PermissionEffect::Deny) {
                        $result['deny'][$row->permission_code] = true;

                        continue;
                    }
                    $result['grants'][$row->permission_code][] = ['scope' => $scope, 'scope_id' => $assignment->scope_id, 'data' => $row->data_scope];
                }
            }
        }

        return $this->effective[$user->id] = $result;
    }
}
