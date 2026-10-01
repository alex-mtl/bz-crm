<?php

declare(strict_types=1);

namespace App\Domain\Access\Actions;

use App\Domain\Access\AuthorizationService;
use App\Domain\Access\Enums\PermissionEffect;
use App\Domain\Access\Exceptions\PermissionEscalation;
use App\Domain\Access\Models\Role;
use App\Domain\Access\Models\RolePermission;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Audit\EventJournal;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The administrator's half of field visibility (Д-13): which roles may see which groups of profile fields.
 * Touches only the field-group codes of a role — nothing else in the role changes.
 */
final readonly class SetFieldGroupRights
{
    public const string PREFIX = 'people.fields.';

    public function __construct(
        private AuthorizationService $authorization,
        private PermissionRegistry $registry,
        private EventJournal $journal,
    ) {}

    /**
     * Field-group codes of the catalog.
     *
     * @return list<string>
     */
    public function codes(): array
    {
        $codes = [];
        foreach ($this->registry->all() as $definition) {
            if (str_starts_with($definition->code, self::PREFIX)) {
                $codes[] = $definition->code;
            }
        }

        return $codes;
    }

    /**
     * @param  list<string>  $allowed  the field groups the role sees; the other groups are taken away
     */
    public function __invoke(User $actor, Role $role, array $allowed): void
    {
        $this->authorization->authorize($actor, 'access.field_rules.manage');

        $groups = $this->codes();
        $allowed = array_values(array_intersect($groups, $allowed));
        $current = $role->permissions()->where('effect', PermissionEffect::Allow)->whereIn('permission_code', $groups)->pluck('permission_code')->all();
        $add = array_values(array_diff($allowed, $current));
        $remove = array_values(array_diff($current, $allowed));
        if ($add === [] && $remove === []) {
            return;
        }
        // Д-17: nobody grants what they do not hold themselves.
        $missing = array_values(array_diff($add, $this->authorization->allowedCodes($actor)));
        if ($missing !== []) {
            throw PermissionEscalation::missing($missing);
        }

        DB::transaction(function () use ($role, $add, $remove): void {
            $role->permissions()->where('effect', PermissionEffect::Allow)->whereIn('permission_code', $remove)->delete();
            foreach ($add as $code) {
                RolePermission::query()->create(['role_id' => $role->id, 'permission_code' => $code, 'effect' => PermissionEffect::Allow]);
            }
            $this->journal->record('access.role.field_rules_changed', $role, ['removed' => $remove], ['added' => $add]);
        });

        $this->authorization->forget();
    }
}
