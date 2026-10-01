<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Access\AuthorizationService;
use App\Domain\Audit\EventJournal;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Signs the user out everywhere (ФО §3.1, ADR-007 п.7): bumps the session epoch checked on every
 * request and rotates the remember-me token so no cookie can silently sign them back in.
 */
final readonly class TerminateUserSessions
{
    public function __construct(private AuthorizationService $authorization, private EventJournal $journal) {}

    public function __invoke(User $actor, User $target, string $reason = 'manual'): void
    {
        $this->authorization->authorize($actor, 'users.sessions.terminate');
        $this->terminate($target, $reason);
    }

    /**
     * Also used internally (deactivation, forced password reset) under the caller's own authorization.
     */
    public function terminate(User $target, string $reason): void
    {
        DB::transaction(function () use ($target, $reason): void {
            // Read the stored value: the in-memory model may be stale or freshly created without it.
            $current = (int) $target->newQuery()->whereKey($target->id)->lockForUpdate()->value('session_epoch');
            $target->forceFill([
                'session_epoch' => $current + 1,
                'remember_token' => Str::random(60),
            ])->save();
            $this->journal->record('auth.sessions.terminated', $target, [], ['reason' => $reason]);
        });
    }
}
