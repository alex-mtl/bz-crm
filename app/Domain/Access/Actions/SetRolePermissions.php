<?php

declare(strict_types=1);

namespace App\Domain\Access\Actions;

use App\Domain\Access\AuthorizationService;
use App\Domain\Access\Enums\PermissionEffect;
use App\Domain\Access\Models\Role;
use App\Domain\Access\Models\RolePermission;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Audit\EventJournal;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class SetRolePermissions
{
    public function __construct(
        private AuthorizationService $authorization,
        private PermissionRegistry $registry,
        private EventJournal $journal,
    ) {}

    /**
     * Replaces the role's permission set, keeping the data layer of the grants that stay. Granting a reserved code
     * is an explicit act and is journaled separately.
     *
     * @param  list<string>  $allow
     * @param  list<string>  $deny
     */
    public function __invoke(User $actor, Role $role, array $allow, array $deny = []): void
    {
        $this->authorization->authorize($actor, 'roles.manage');

        $allow = array_values(array_unique($allow));
        $deny = array_values(array_unique($deny));
        foreach ([...$allow, ...$deny] as $code) {
            if (! $this->registry->has($code)) {
                throw new InvalidArgumentException("Unknown permission [{$code}].");
            }
        }
        if (array_intersect($allow, $deny) !== []) {
            throw new InvalidArgumentException('A permission cannot be both allowed and denied in one role.');
        }

        DB::transaction(function () use ($role, $allow, $deny): void {
            $old = $role->permissions()->get()->mapWithKeys(fn (RolePermission $p) => [$p->permission_code => $p->effect->value])->all();

            // The data layer of a grant ("own" / "related") is not edited here and must survive the rewrite:
            // dropping it would silently widen the role. A newly added code starts with the layer of the starter setup.
            $layers = $role->permissions()->where('effect', PermissionEffect::Allow)->pluck('data_scope', 'permission_code')->all();

            $role->permissions()->delete();
            foreach ($allow as $code) {
                RolePermission::query()->create([
                    'role_id' => $role->id, 'permission_code' => $code, 'effect' => PermissionEffect::Allow,
                    'data_scope' => array_key_exists($code, $layers) ? $layers[$code] : ($this->registry->get($code)->dataScopes[$role->code] ?? null),
                ]);
            }
            foreach ($deny as $code) {
                RolePermission::query()->create(['role_id' => $role->id, 'permission_code' => $code, 'effect' => PermissionEffect::Deny]);
            }

            $new = [...array_fill_keys($allow, 'allow'), ...array_fill_keys($deny, 'deny')];
            ksort($old);
            ksort($new);
            $this->journal->record('access.role.permissions_changed', $role, ['permissions' => $old], ['permissions' => $new]);

            foreach ($allow as $code) {
                if ($this->registry->isReserved($code) && ($old[$code] ?? null) !== 'allow') {
                    $this->journal->record('access.reserved_permission.granted', $role, [], ['permission' => $code]);
                }
            }
        });

        $this->authorization->forget();
    }
}
