<?php

declare(strict_types=1);

namespace App\Domain\Access\Actions;

use App\Domain\Access\AuthorizationService;
use App\Domain\Access\Enums\PermissionEffect;
use App\Domain\Access\Exceptions\AccessRuleViolation;
use App\Domain\Access\Exceptions\PermissionEscalation;
use App\Domain\Access\Models\Role;
use App\Domain\Access\Models\RolePermission;
use App\Domain\Access\Models\UserRole;
use App\Domain\Access\Scopes\ScopeType;
use App\Domain\Audit\EventJournal;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Role assignments with a scope (ФО §6.2, ADR-008) and delegation — a temporary role with an end date,
 * revoked automatically (ФО §3.5). "Not more than you have" (Д-17) covers both the rights and the scope.
 */
final readonly class AssignRole
{
    public const string KIND_ROLE = 'role';

    public const string KIND_DELEGATION = 'delegation';

    public function __construct(private AuthorizationService $authorization, private EventJournal $journal) {}

    /**
     * @param  string  $viaPermission  the right under which the grant happens (roles.assign, users.approve, users.invite)
     *
     * @throws PermissionEscalation
     */
    public function __invoke(
        User $actor,
        User $target,
        Role $role,
        ?Carbon $expiresAt = null,
        string $viaPermission = 'roles.assign',
        ScopeType $scope = ScopeType::Organization,
        ?int $scopeId = null,
        ?string $reason = null,
    ): UserRole {
        $this->authorization->authorize($actor, $viaPermission, $target);
        $this->ensureValidScope($scope, $scopeId);
        $this->ensureNotMoreThanActorHas($actor, $role, $target, $scope, $scopeId, $target);

        return $this->store($actor, $target, $role, $expiresAt, $scope, $scopeId, self::KIND_ROLE, $reason);
    }

    /**
     * Delegation (ФО §6.2 "исполняющий обязанности"): an end date and a reason are mandatory.
     */
    public function delegate(User $actor, User $target, Role $role, Carbon $expiresAt, string $reason, ScopeType $scope, ?int $scopeId = null): UserRole
    {
        $this->authorization->authorize($actor, 'delegations.manage', $target);
        $this->ensureValidScope($scope, $scopeId);
        if (trim($reason) === '') {
            throw AccessRuleViolation::because('reason_required');
        }
        if ($expiresAt->isPast()) {
            throw AccessRuleViolation::because('end_date_in_past');
        }
        $this->ensureNotMoreThanActorHas($actor, $role, $target, $scope, $scopeId, $target);

        return $this->store($actor, $target, $role, $expiresAt, $scope, $scopeId, self::KIND_DELEGATION, trim($reason));
    }

    /**
     * Д-17: a role may be given only if every right it allows is a right the granter has — and, for rights
     * that follow the assignment's scope, the granter holds them in a scope that covers the new one.
     * The refusal is journaled outside any transaction so it survives the rollback.
     *
     * @param  Model|array{type: string, id: int|string}|null  $subject
     */
    public function ensureNotMoreThanActorHas(
        User $actor,
        Role $role,
        Model|array|null $subject = null,
        ScopeType $scope = ScopeType::Organization,
        ?int $scopeId = null,
        ?User $holder = null,
    ): void {
        $rows = self::allowRows($role);
        $allowed = $this->authorization->allowedCodes($actor);
        $missing = [];
        foreach ($rows as $row) {
            if (! in_array($row->permission_code, $allowed, true)) {
                $missing[] = $row->permission_code;
            } elseif ($row->data_scope === null && ! $this->authorization->coversScope($actor, $row->permission_code, $scope, $scopeId, $holder)) {
                $missing[] = $row->permission_code.' ('.$scope->label().')';
            }
        }

        if ($missing !== []) {
            $missing = array_values(array_unique($missing));
            sort($missing);
            $this->journal->record('access.escalation.denied', $subject, [], [
                'role' => $role->code, 'scope' => $scope->value, 'scope_id' => $scopeId, 'missing' => $missing,
            ]);
            throw PermissionEscalation::missing($missing);
        }
    }

    /**
     * Allowed rows of the role and of the roles it inherits from.
     *
     * @return list<RolePermission>
     */
    public static function allowRows(Role $role): array
    {
        $ids = [];
        for ($current = $role; $current !== null && ! in_array($current->id, $ids, true); $current = $current->parentRole) {
            $ids[] = $current->id;
        }

        return RolePermission::query()->whereIn('role_id', $ids)->where('effect', PermissionEffect::Allow)->get()->all();
    }

    private function ensureValidScope(ScopeType $scope, ?int $scopeId): void
    {
        if ($scope->needsId() !== ($scopeId !== null)) {
            throw AccessRuleViolation::because('scope_invalid');
        }
    }

    private function store(User $actor, User $target, Role $role, ?Carbon $expiresAt, ScopeType $scope, ?int $scopeId, string $kind, ?string $reason): UserRole
    {
        $assignment = DB::transaction(function () use ($actor, $target, $role, $expiresAt, $scope, $scopeId, $kind, $reason): UserRole {
            $assignment = UserRole::query()->updateOrCreate(
                ['user_id' => $target->id, 'role_id' => $role->id, 'scope_type' => $scope->stored(), 'scope_id' => $scopeId],
                [
                    'granted_by_user_id' => $actor->id, 'granted_at' => now(), 'expires_at' => $expiresAt,
                    'kind' => $kind, 'reason' => $reason, 'expiry_recorded_at' => null,
                ],
            );
            $this->journal->record($kind === self::KIND_DELEGATION ? 'access.role.delegated' : 'access.role.assigned', $target, [], [
                'role' => $role->code,
                'scope' => $scope->value,
                'scope_id' => $scopeId,
                'expires_at' => $expiresAt?->toIso8601String(),
                'reason' => $reason,
            ]);

            return $assignment;
        });

        $this->authorization->forget($target);

        return $assignment;
    }
}
