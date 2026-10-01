<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Access\AuthorizationService;
use App\Domain\Audit\EventJournal;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Events\UserDeactivated;
use App\Domain\Identity\Exceptions\IdentityRuleViolation;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Deactivation keeps all data and history (ФО §6.1) and signs the user out everywhere.
 */
final readonly class SetUserActive
{
    public function __construct(
        private AuthorizationService $authorization,
        private TerminateUserSessions $sessions,
        private EventJournal $journal,
    ) {}

    public function __invoke(User $actor, User $target, bool $active): void
    {
        $this->authorization->authorize($actor, 'users.deactivate');
        if ($actor->id === $target->id) {
            throw IdentityRuleViolation::notOnYourself();
        }
        $wanted = $active ? UserStatus::Active : UserStatus::Deactivated;
        if ($target->status === $wanted || ! in_array($target->status, [UserStatus::Active, UserStatus::Deactivated], true)) {
            return;
        }

        DB::transaction(function () use ($target, $active, $wanted): void {
            $old = $target->status->value;
            $target->update(['status' => $wanted, 'deactivated_at' => $active ? null : now()]);
            $this->journal->record($active ? 'identity.user.reactivated' : 'identity.user.deactivated', $target, ['status' => $old], ['status' => $wanted->value]);
            if (! $active) {
                $this->sessions->terminate($target, 'deactivated');
            }
        });

        if (! $active) {
            event(new UserDeactivated($target, $actor->id));
        }
    }
}
