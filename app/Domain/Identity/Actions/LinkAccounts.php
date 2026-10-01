<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Access\AuthorizationService;
use App\Domain\Audit\EventJournal;
use App\Domain\Identity\Enums\ApplicationStatus;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Exceptions\IdentityRuleViolation;
use App\Domain\Identity\Models\RegistrationApplication;
use App\Domain\Identity\Models\SocialIdentity;
use App\Domain\Identity\Models\User;
use App\Domain\People\Enums\LinkHintStatus;
use App\Domain\People\Models\AccountLinkHint;
use Illuminate\Support\Facades\DB;

/**
 * Manual resolution of a "second account" hint (Д-10). History is never deleted:
 *  - the existing person already has an account → the new sign-in methods move to it,
 *    the new account is deactivated and its application closed as "linked";
 *  - the existing person has no account (e.g. a candidate) → the new account is attached to that card
 *    and its application keeps waiting for approval.
 * In both cases the new card is marked as a duplicate of the existing one.
 */
final readonly class LinkAccounts
{
    public function __construct(private AuthorizationService $authorization, private EventJournal $journal) {}

    public function __invoke(User $actor, AccountLinkHint $hint): void
    {
        $this->authorization->authorize($actor, 'users.link_accounts');
        if ($hint->status !== LinkHintStatus::Open) {
            throw IdentityRuleViolation::hintNotOpen();
        }

        DB::transaction(function () use ($actor, $hint): void {
            $newPerson = $hint->newPerson;
            $existingPerson = $hint->existingPerson;
            $newUser = $newPerson->user;
            $existingUser = $existingPerson->user;

            if ($newUser !== null && $existingUser !== null) {
                SocialIdentity::query()->where('user_id', $newUser->id)->update(['user_id' => $existingUser->id]);
                $newUser->update(['status' => UserStatus::Deactivated, 'deactivated_at' => now()]);
                RegistrationApplication::query()->where('user_id', $newUser->id)->where('status', ApplicationStatus::Pending)
                    ->update(['status' => ApplicationStatus::Linked, 'reviewed_by_user_id' => $actor->id, 'reviewed_at' => now()]);
                $mode = 'moved_sign_in_methods';
            } elseif ($newUser !== null) {
                $newUser->update(['person_id' => $existingPerson->id]);
                $mode = 'attached_account_to_card';
            } else {
                $mode = 'cards_only';
            }

            $newPerson->update(['duplicate_of_person_id' => $existingPerson->id]);
            $hint->update(['status' => LinkHintStatus::Linked, 'resolved_by_user_id' => $actor->id, 'resolved_at' => now()]);

            $this->journal->record('identity.accounts.linked', $hint, [], [
                'mode' => $mode,
                'new_person_id' => $newPerson->id,
                'existing_person_id' => $existingPerson->id,
                'new_user_id' => $newUser?->id,
                'existing_user_id' => $existingUser?->id,
            ]);
        });
    }
}
