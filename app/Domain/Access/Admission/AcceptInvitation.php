<?php

declare(strict_types=1);

namespace App\Domain\Access\Admission;

use App\Domain\Access\AuthorizationService;
use App\Domain\Access\Enums\PermissionEffect;
use App\Domain\Access\Models\Role;
use App\Domain\Access\Models\UserRole;
use App\Domain\Audit\EventJournal;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Exceptions\IdentityRuleViolation;
use App\Domain\Identity\Models\Invitation;
use App\Domain\Identity\Models\User;
use App\Domain\People\Models\Person;
use App\Domain\People\Models\PersonStatusHistory;
use Illuminate\Support\Facades\DB;

/**
 * The invited person creates their account: active at once, e-mail confirmed by the link itself.
 * Roles are granted on behalf of the inviter, re-checked against the inviter's rights *now*.
 */
final readonly class AcceptInvitation
{
    public function __construct(private AuthorizationService $authorization, private EventJournal $journal) {}

    /**
     * A new card — or, for an invitation issued for an existing card (Д-22), that very card: it keeps its history
     * (leads, appeals, interactions) and only changes its type.
     */
    private function cardFor(Invitation $invitation, string $firstName, ?string $lastName, string $locale): Person
    {
        $existing = $invitation->person_id !== null ? Person::query()->find($invitation->person_id) : null;
        if ($existing === null) {
            return Person::query()->create([
                'first_name' => trim($firstName),
                'last_name' => $lastName !== null ? trim($lastName) : null,
                'email' => $invitation->email,
                'person_type' => $invitation->person_type,
                'preferred_locale' => $locale,
            ]);
        }
        if ($existing->user !== null || $existing->isArchived()) {
            throw IdentityRuleViolation::invitationUnusable();
        }

        $oldType = $existing->person_type;
        $existing->forceFill([
            'email' => $existing->email ?? $invitation->email,
            'person_type' => $invitation->person_type,
            'preferred_locale' => $locale,
        ])->save();
        if ($oldType !== $existing->person_type) {
            PersonStatusHistory::query()->create([
                'person_id' => $existing->id, 'kind' => PersonStatusHistory::TYPE, 'old_value' => $oldType,
                'new_value' => $existing->person_type, 'changed_by_user_id' => $invitation->invited_by_user_id,
            ]);
        }

        return $existing;
    }

    public function findUsable(string $token): Invitation
    {
        $invitation = Invitation::query()->where('token_hash', Invitation::hashToken($token))->first();
        if ($invitation === null || ! $invitation->isUsable()) {
            throw IdentityRuleViolation::invitationUnusable();
        }

        return $invitation;
    }

    public function __invoke(string $token, string $firstName, ?string $lastName, string $password, string $locale): User
    {
        $invitation = $this->findUsable($token);
        if (User::query()->where('email', $invitation->email)->exists()) {
            throw IdentityRuleViolation::emailTaken();
        }

        $user = DB::transaction(function () use ($invitation, $firstName, $lastName, $password, $locale): User {
            $person = $this->cardFor($invitation, $firstName, $lastName, $locale);
            $user = User::query()->create([
                'person_id' => $person->id,
                'email' => $invitation->email,
                'email_verified_at' => now(),
                'password' => $password,
                'status' => UserStatus::Active,
                'locale' => $locale,
            ]);

            $inviter = User::query()->find($invitation->invited_by_user_id);
            $inviterCodes = $inviter !== null ? $this->authorization->allowedCodes($inviter) : [];
            $granted = [];
            foreach (Role::query()->whereIn('code', $invitation->role_codes)->get() as $role) {
                $needed = $role->permissions()->where('effect', PermissionEffect::Allow)->pluck('permission_code')->all();
                if (array_diff($needed, $inviterCodes) !== []) {
                    $this->journal->record('access.escalation.denied', $user, [], ['role' => $role->code, 'via' => 'invitation']);

                    continue;
                }
                UserRole::query()->create([
                    'user_id' => $user->id,
                    'role_id' => $role->id,
                    'granted_by_user_id' => $invitation->invited_by_user_id,
                    'granted_at' => now(),
                ]);
                $this->journal->record('access.role.assigned', $user, [], ['role' => $role->code, 'via' => 'invitation']);
                $granted[] = $role->code;
            }

            $invitation->update(['accepted_at' => now(), 'accepted_user_id' => $user->id]);
            $this->journal->record('admission.invitation.accepted', $invitation, [], ['user_id' => $user->id, 'roles' => $granted]);

            return $user;
        });

        $this->authorization->forget($user);

        return $user;
    }
}
