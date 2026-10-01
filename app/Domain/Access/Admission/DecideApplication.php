<?php

declare(strict_types=1);

namespace App\Domain\Access\Admission;

use App\Domain\Access\Actions\AssignRole;
use App\Domain\Access\AuthorizationService;
use App\Domain\Access\Models\Role;
use App\Domain\Audit\EventJournal;
use App\Domain\Identity\Enums\ApplicationStatus;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Exceptions\IdentityRuleViolation;
use App\Domain\Identity\Models\RegistrationApplication;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Notifications\ApplicationDecided;
use Illuminate\Support\Facades\DB;

/**
 * Review of a membership application (ФО §6.1): approve with roles, or reject with a reason
 * the applicant sees. Roles obey "not more than you have" (Д-17).
 */
final readonly class DecideApplication
{
    public function __construct(
        private AuthorizationService $authorization,
        private AssignRole $assignRole,
        private EventJournal $journal,
    ) {}

    /**
     * @param  list<string>  $roleCodes
     */
    public function approve(User $actor, RegistrationApplication $application, array $roleCodes, string $personType = 'employee'): void
    {
        $this->authorization->authorize($actor, 'users.approve');
        $this->ensurePending($application);
        $roles = Role::query()->whereIn('code', $roleCodes)->get();
        foreach ($roles as $role) {
            $this->assignRole->ensureNotMoreThanActorHas($actor, $role, $application->user);
        }

        DB::transaction(function () use ($actor, $application, $roles, $personType): void {
            $user = $application->user;
            $user->update(['status' => UserStatus::Active]);
            $user->person->update(['person_type' => $personType]);
            foreach ($roles as $role) {
                ($this->assignRole)($actor, $user, $role, null, 'users.approve');
            }
            $application->update([
                'status' => ApplicationStatus::Approved,
                'reviewed_by_user_id' => $actor->id,
                'reviewed_at' => now(),
                'granted_roles' => $roles->pluck('code')->all(),
            ]);
            $this->journal->record('admission.application.approved', $application, [], [
                'user_id' => $user->id,
                'roles' => $roles->pluck('code')->all(),
                'person_type' => $personType,
            ]);
        });

        $this->authorization->forget($application->user);
        $application->user->notify(new ApplicationDecided(true));
    }

    public function reject(User $actor, RegistrationApplication $application, string $reason): void
    {
        $this->authorization->authorize($actor, 'users.approve');
        $this->ensurePending($application);
        if (trim($reason) === '') {
            throw IdentityRuleViolation::reasonRequired();
        }

        DB::transaction(function () use ($actor, $application, $reason): void {
            $application->user->update(['status' => UserStatus::Rejected]);
            $application->update([
                'status' => ApplicationStatus::Rejected,
                'reviewed_by_user_id' => $actor->id,
                'reviewed_at' => now(),
                'rejection_reason' => trim($reason),
            ]);
            $this->journal->record('admission.application.rejected', $application, [], ['reason' => trim($reason)]);
        });

        $application->user->notify(new ApplicationDecided(false, trim($reason)));
    }

    private function ensurePending(RegistrationApplication $application): void
    {
        if ($application->status !== ApplicationStatus::Pending) {
            throw IdentityRuleViolation::applicationNotPending();
        }
    }
}
