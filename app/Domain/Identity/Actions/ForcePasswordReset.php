<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Access\AuthorizationService;
use App\Domain\Audit\EventJournal;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;

/**
 * Admin-forced reset (ФО §6.1): the old password stops working at once, the user gets a reset link.
 */
final readonly class ForcePasswordReset
{
    public function __construct(
        private AuthorizationService $authorization,
        private TerminateUserSessions $sessions,
        private EventJournal $journal,
    ) {}

    public function __invoke(User $actor, User $target): void
    {
        $this->authorization->authorize($actor, 'users.password.reset');

        DB::transaction(function () use ($target): void {
            $target->forceFill(['password' => null])->save();
            $this->journal->record('identity.password.reset_forced', $target);
            $this->sessions->terminate($target, 'password_reset_forced');
        });

        if ($target->email !== null) {
            Password::broker()->sendResetLink(['email' => $target->email]);
        }
    }
}
