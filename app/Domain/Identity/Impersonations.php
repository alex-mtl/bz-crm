<?php

declare(strict_types=1);

namespace App\Domain\Identity;

use App\Domain\Access\AuthorizationService;
use App\Domain\Audit\Enums\ActingAs;
use App\Domain\Audit\EventJournal;
use App\Domain\Audit\JournalContext;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Exceptions\IdentityRuleViolation;
use App\Domain\Identity\Models\Impersonation;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Notifications\ImpersonationNotice;
use Illuminate\Support\Facades\DB;

/**
 * Impersonation (Д-19): a holder of users.impersonate (by default only the super admin) signs in as another user
 * and sees and acts with that user's rights — confidential layers included. Every session needs a reason, lasts
 * at most MAX_MINUTES, is journaled and the user is told. Entries made during it carry acting_as = impersonation,
 * the real actor (the impersonator) and the account used.
 */
final readonly class Impersonations
{
    public const int MAX_MINUTES = 30;

    public function __construct(
        private AuthorizationService $authorization,
        private EventJournal $journal,
        private JournalContext $context,
    ) {}

    public function begin(User $actor, User $target, string $reason): Impersonation
    {
        $this->authorization->authorize($actor, 'users.impersonate');

        $reason = trim($reason);
        if ($reason === '') {
            throw IdentityRuleViolation::reasonRequired();
        }
        if ($actor->is($target)) {
            throw IdentityRuleViolation::notOnYourself();
        }
        if ($target->status !== UserStatus::Active) {
            throw IdentityRuleViolation::impersonationTargetInactive();
        }
        // No chains: whoever may impersonate (another super admin) cannot be impersonated.
        if ($this->authorization->can($target, 'users.impersonate')) {
            throw IdentityRuleViolation::impersonationTargetPrivileged();
        }

        $impersonation = DB::transaction(function () use ($actor, $target, $reason): Impersonation {
            $impersonation = Impersonation::query()->create([
                'impersonator_user_id' => $actor->id,
                'target_user_id' => $target->id,
                'reason' => $reason,
                'started_at' => now(),
                'expires_at' => now()->addMinutes(self::MAX_MINUTES),
                'ip_address' => $this->context->ipAddress,
            ]);
            $this->journal->record('identity.impersonation.started', $target, [], [
                'impersonation_id' => $impersonation->id,
                'reason' => $reason,
                'expires_at' => $impersonation->expires_at->toIso8601String(),
            ]);

            return $impersonation;
        });

        $target->notify(new ImpersonationNotice($impersonation));

        return $impersonation;
    }

    public function end(Impersonation $impersonation, string $endReason = Impersonation::END_STOPPED): void
    {
        if ($impersonation->ended_at !== null) {
            return;
        }

        DB::transaction(function () use ($impersonation, $endReason): void {
            $impersonation->update(['ended_at' => now(), 'end_reason' => $endReason]);
            $this->journal->record('identity.impersonation.ended', $impersonation->target, [], [
                'impersonation_id' => $impersonation->id,
                'end_reason' => $endReason,
                'minutes' => (int) $impersonation->started_at->diffInMinutes($impersonation->ended_at),
            ]);
        });
    }

    /**
     * Marks the journal context for the rest of the request: the impersonator acts, through the target's account.
     */
    public function applyToContext(Impersonation $impersonation): void
    {
        $impersonator = $impersonation->impersonator;

        $this->context->asUser($impersonator->id, $impersonator->person_id);
        $this->context->actingAs = ActingAs::Impersonation;
        $this->context->actingAsRef = (string) $impersonation->id;
        $this->context->extra['on_behalf_of_user_id'] = $impersonation->target_user_id;
    }
}
