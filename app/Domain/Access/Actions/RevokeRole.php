<?php

declare(strict_types=1);

namespace App\Domain\Access\Actions;

use App\Domain\Access\AuthorizationService;
use App\Domain\Access\Models\UserRole;
use App\Domain\Audit\EventJournal;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class RevokeRole
{
    public function __construct(
        private AuthorizationService $authorization,
        private AssignRole $assignRole,
        private EventJournal $journal,
    ) {}

    public function __invoke(User $actor, UserRole $assignment): void
    {
        $this->authorization->authorize($actor, 'roles.assign');
        $target = User::query()->findOrFail($assignment->user_id);
        // Symmetric with granting: you cannot take away what you could not have given.
        $this->assignRole->ensureNotMoreThanActorHas($actor, $assignment->role, $target);

        DB::transaction(function () use ($assignment, $target): void {
            $code = $assignment->role->code;
            $assignment->delete();
            $this->journal->record('access.role.revoked', $target, ['role' => $code]);
        });

        $this->authorization->forget($target);
    }
}
