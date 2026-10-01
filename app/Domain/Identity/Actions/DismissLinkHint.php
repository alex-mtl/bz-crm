<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Access\AuthorizationService;
use App\Domain\Audit\EventJournal;
use App\Domain\Identity\Exceptions\IdentityRuleViolation;
use App\Domain\Identity\Models\User;
use App\Domain\People\Enums\LinkHintStatus;
use App\Domain\People\Models\AccountLinkHint;
use Illuminate\Support\Facades\DB;

/**
 * "These are different people" — the hint for this pair is not shown again (Д-10).
 */
final readonly class DismissLinkHint
{
    public function __construct(private AuthorizationService $authorization, private EventJournal $journal) {}

    public function __invoke(User $actor, AccountLinkHint $hint): void
    {
        $this->authorization->authorize($actor, 'users.link_accounts');
        if ($hint->status !== LinkHintStatus::Open) {
            throw IdentityRuleViolation::hintNotOpen();
        }

        DB::transaction(function () use ($actor, $hint): void {
            $hint->update(['status' => LinkHintStatus::Dismissed, 'resolved_by_user_id' => $actor->id, 'resolved_at' => now()]);
            $this->journal->record('people.link_hint.dismissed', $hint);
        });
    }
}
