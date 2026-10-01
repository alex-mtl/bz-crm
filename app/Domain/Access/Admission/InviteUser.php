<?php

declare(strict_types=1);

namespace App\Domain\Access\Admission;

use App\Domain\Access\Actions\AssignRole;
use App\Domain\Access\AuthorizationService;
use App\Domain\Access\Models\Role;
use App\Domain\Audit\EventJournal;
use App\Domain\Identity\Exceptions\IdentityRuleViolation;
use App\Domain\Identity\Models\Invitation;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Notifications\InvitationSent;
use App\Domain\People\Models\Person;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Invitation — the main way in (ФО §6.1). Roles in the invitation obey "not more than you have" (Д-17).
 */
final readonly class InviteUser
{
    public function __construct(
        private AuthorizationService $authorization,
        private AssignRole $assignRole,
        private EventJournal $journal,
    ) {}

    /**
     * @param  list<string>  $roleCodes
     * @return array{invitation: Invitation, token: string}
     */
    public function __invoke(User $actor, string $email, array $roleCodes, ?string $firstName = null, ?string $lastName = null, string $personType = 'employee', int $validDays = 7, ?Person $forPerson = null): array
    {
        $this->authorization->authorize($actor, 'users.invite');
        if ($forPerson !== null) {
            // Д-22: access for a card that already exists — the account will belong to that card, history kept.
            $this->authorization->authorize($actor, 'people.read', $forPerson);
            if ($forPerson->user !== null || $forPerson->isArchived()) {
                throw IdentityRuleViolation::cardCannotGetAccess();
            }
            $firstName = $forPerson->first_name;
            $lastName = $forPerson->last_name;
        }
        $email = Str::lower(trim($email));
        if (User::query()->where('email', $email)->exists()) {
            throw IdentityRuleViolation::emailTaken();
        }

        $roles = Role::query()->whereIn('code', $roleCodes)->get();
        foreach ($roles as $role) {
            $this->assignRole->ensureNotMoreThanActorHas($actor, $role, ['type' => 'invitation', 'id' => $email]);
        }

        $token = Str::random(48);
        $invitation = DB::transaction(function () use ($actor, $email, $roles, $firstName, $lastName, $personType, $validDays, $token, $forPerson): Invitation {
            $invitation = Invitation::query()->create([
                'email' => $email,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'person_type' => $personType,
                'token_hash' => Invitation::hashToken($token),
                'role_codes' => $roles->pluck('code')->all(),
                'invited_by_user_id' => $actor->id,
                'expires_at' => now()->addDays($validDays),
                'person_id' => $forPerson?->id,
            ]);
            $this->journal->record('admission.invitation.sent', $invitation, [], [
                'email' => $email,
                'roles' => $invitation->role_codes,
                'person_id' => $invitation->person_id,
                'expires_at' => $invitation->expires_at->toIso8601String(),
            ]);

            return $invitation;
        });

        Notification::route('mail', $email)->notify(
            new InvitationSent(route('invitation.accept', $token), $invitation->expires_at, $actor->person->fullName()),
        );

        return ['invitation' => $invitation, 'token' => $token];
    }
}
