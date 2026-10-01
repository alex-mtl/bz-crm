<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Audit\EventJournal;
use App\Domain\Identity\Data\ExternalIdentity;
use App\Domain\Identity\Enums\ApplicationStatus;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Exceptions\IdentityRuleViolation;
use App\Domain\Identity\Models\RegistrationApplication;
use App\Domain\Identity\Models\SocialIdentity;
use App\Domain\Identity\Models\User;
use App\Domain\People\Actions\RecordLinkHints;
use App\Domain\People\Models\Person;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * External sign-in (ADR-007, Д-10):
 *  - known external account → that user;
 *  - signed-in user connecting a provider → linked to them (they proved both identities);
 *  - otherwise → a NEW pending application, even if the e-mail matches someone: only a hint is created.
 */
final readonly class SignInWithProvider
{
    public function __construct(private RecordLinkHints $recordHints, private EventJournal $journal) {}

    public function __invoke(ExternalIdentity $identity, ?User $current = null): User
    {
        $existing = SocialIdentity::query()
            ->where('provider', $identity->provider)
            ->where('provider_user_id', $identity->providerUserId)
            ->first();

        if ($existing !== null) {
            if ($current !== null && $current->id !== $existing->user_id) {
                throw IdentityRuleViolation::identityLinkedElsewhere();
            }

            return $existing->user;
        }

        if ($current !== null) {
            return DB::transaction(function () use ($identity, $current): User {
                $this->link($current, $identity);
                $this->journal->record('identity.provider.linked', $current, [], ['provider' => $identity->provider]);

                return $current;
            });
        }

        return DB::transaction(function () use ($identity): User {
            $email = $identity->email !== null ? Str::lower($identity->email) : null;
            $person = Person::query()->create([
                'first_name' => $identity->firstName ?? ($email !== null ? Str::before($email, '@') : $identity->provider),
                'last_name' => $identity->lastName,
                'email' => $email,
                'person_type' => 'applicant',
                'preferred_locale' => app()->getLocale(),
            ]);
            $user = User::query()->create([
                'person_id' => $person->id,
                // A login e-mail already used by another account is not reused (Д-10).
                'email' => $email !== null && User::query()->where('email', $email)->doesntExist() ? $email : null,
                'email_verified_at' => $identity->emailVerified ? now() : null,
                'status' => UserStatus::PendingApproval,
                'locale' => app()->getLocale(),
            ]);
            $this->link($user, $identity);
            RegistrationApplication::query()->create([
                'user_id' => $user->id,
                'channel' => 'oauth:'.$identity->provider,
                'status' => ApplicationStatus::Pending,
                'submitted_at' => now(),
            ]);
            $this->journal->record('identity.registration.submitted', $user, [], ['channel' => 'oauth:'.$identity->provider]);
            ($this->recordHints)($person);

            return $user;
        });
    }

    private function link(User $user, ExternalIdentity $identity): void
    {
        SocialIdentity::query()->create([
            'user_id' => $user->id,
            'provider' => $identity->provider,
            'provider_user_id' => $identity->providerUserId,
            'email' => $identity->email,
            'linked_at' => now(),
        ]);
    }
}
