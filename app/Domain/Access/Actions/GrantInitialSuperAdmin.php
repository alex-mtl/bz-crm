<?php

declare(strict_types=1);

namespace App\Domain\Access\Actions;

use App\Domain\Access\AuthorizationService;
use App\Domain\Access\Enums\SystemRole;
use App\Domain\Access\Models\Role;
use App\Domain\Access\Models\UserRole;
use App\Domain\Audit\EventJournal;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Bootstrap only: gives the super admin role without a granting user (nobody has rights on a fresh install).
 * Called from the console / seeders, recorded in the journal as a system action.
 */
final readonly class GrantInitialSuperAdmin
{
    public function __construct(private AuthorizationService $authorization, private EventJournal $journal) {}

    public function __invoke(User $user): void
    {
        $role = Role::query()->where('code', SystemRole::SuperAdmin->value)->firstOrFail();

        DB::transaction(function () use ($user, $role): void {
            $assignment = UserRole::query()->firstOrCreate(
                ['user_id' => $user->id, 'role_id' => $role->id, 'scope_type' => null, 'scope_id' => null],
                ['granted_by_user_id' => null, 'granted_at' => now()],
            );
            if ($assignment->wasRecentlyCreated) {
                $this->journal->record('access.role.assigned', $user, [], ['role' => $role->code, 'bootstrap' => true]);
            }
        });

        $this->authorization->forget($user);
    }
}
