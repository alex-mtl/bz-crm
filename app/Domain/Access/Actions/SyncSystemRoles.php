<?php

declare(strict_types=1);

namespace App\Domain\Access\Actions;

use App\Domain\Access\AuthorizationService;
use App\Domain\Access\Enums\PermissionEffect;
use App\Domain\Access\Enums\SystemRole;
use App\Domain\Access\Models\Role;
use App\Domain\Access\Models\RolePermission;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Audit\EventJournal;
use App\Support\Settings\SystemSettings;
use Illuminate\Support\Facades\DB;

/**
 * Reference data (all environments). Creates the starter roles and applies default roles of
 * permission codes that appear for the first time. A code seen once is never re-applied, so a
 * right an admin removed from a system role does not come back. Reserved codes are never granted (Д-17).
 */
final readonly class SyncSystemRoles
{
    public const string INTRODUCED_CODES_KEY = 'access.introduced_permission_codes';

    public function __construct(
        private PermissionRegistry $registry,
        private AuthorizationService $authorization,
        private SystemSettings $settings,
        private EventJournal $journal,
    ) {}

    /**
     * @return array{roles_created: int, permissions_added: int, codes_introduced: int}
     */
    public function __invoke(): array
    {
        $result = DB::transaction(function (): array {
            $roles = [];
            $rolesCreated = 0;
            foreach (SystemRole::cases() as $case) {
                $names = $case->names();
                $role = Role::query()->firstOrCreate(
                    ['code' => $case->value],
                    ['name_ro' => $names['ro'], 'name_ru' => $names['ru'], 'name_en' => $names['en'], 'is_system' => true],
                );
                $rolesCreated += $role->wasRecentlyCreated ? 1 : 0;
                $roles[$case->value] = $role;
            }

            /** @var list<string> $introduced */
            $introduced = $this->settings->get(self::INTRODUCED_CODES_KEY, []);
            $added = 0;
            $newCodes = [];
            foreach ($this->registry->all() as $definition) {
                if (in_array($definition->code, $introduced, true)) {
                    continue;
                }
                $newCodes[] = $definition->code;
                // The super admin holds every non-reserved right: otherwise "not more than you have"
                // would stop them from managing roles that contain it.
                $grantTo = $definition->reserved ? [] : array_unique([SystemRole::SuperAdmin->value, ...$definition->defaultRoles]);
                foreach ($grantTo as $roleCode) {
                    if (! isset($roles[$roleCode])) {
                        continue;
                    }
                    $row = RolePermission::query()->firstOrCreate(
                        ['role_id' => $roles[$roleCode]->id, 'permission_code' => $definition->code],
                        ['effect' => PermissionEffect::Allow, 'data_scope' => $roleCode === SystemRole::SuperAdmin->value ? null : ($definition->dataScopes[$roleCode] ?? null)],
                    );
                    $added += $row->wasRecentlyCreated ? 1 : 0;
                }
            }

            if ($newCodes !== []) {
                $this->settings->put(self::INTRODUCED_CODES_KEY, [...$introduced, ...$newCodes]);
            }

            $result = ['roles_created' => $rolesCreated, 'permissions_added' => $added, 'codes_introduced' => count($newCodes)];
            if ($rolesCreated > 0 || $newCodes !== []) {
                $this->journal->record('access.system_roles.synced', null, [], $result);
            }

            return $result;
        });

        $this->authorization->forget();

        return $result;
    }
}
