<?php

declare(strict_types=1);

namespace App\Domain\Access\Admission;

use App\Domain\Access\AuthorizationService;
use App\Domain\Audit\EventJournal;
use App\Domain\Identity\Exceptions\IdentityRuleViolation;
use App\Domain\Identity\Models\Invitation;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class RevokeInvitation
{
    public function __construct(private AuthorizationService $authorization, private EventJournal $journal) {}

    public function __invoke(User $actor, Invitation $invitation): void
    {
        $this->authorization->authorize($actor, 'users.invite');
        if (! $invitation->isUsable()) {
            throw IdentityRuleViolation::invitationUnusable();
        }

        DB::transaction(function () use ($invitation): void {
            $invitation->update(['revoked_at' => now()]);
            $this->journal->record('admission.invitation.revoked', $invitation);
        });
    }
}
