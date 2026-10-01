<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Audit\EventJournal;
use App\Domain\Identity\Enums\ApplicationStatus;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Exceptions\IdentityRuleViolation;
use App\Domain\Identity\Models\RegistrationApplication;
use App\Domain\Identity\Models\User;
use App\Domain\People\Actions\RecordLinkHints;
use App\Domain\People\Models\Person;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Self-registration by e-mail (ФО §6.1): zero rights, "application pending", e-mail must be confirmed.
 * Never linked to an existing card automatically (Д-10).
 */
final readonly class RegisterWithEmail
{
    public function __construct(private RecordLinkHints $recordHints, private EventJournal $journal) {}

    public function __invoke(string $firstName, ?string $lastName, string $email, string $password, string $locale): User
    {
        $email = Str::lower(trim($email));
        if (User::query()->where('email', $email)->exists()) {
            throw IdentityRuleViolation::emailTaken();
        }

        $user = DB::transaction(function () use ($firstName, $lastName, $email, $password, $locale): User {
            $person = Person::query()->create([
                'first_name' => trim($firstName),
                'last_name' => $lastName !== null ? trim($lastName) : null,
                'email' => $email,
                'person_type' => 'applicant',
                'preferred_locale' => $locale,
            ]);
            $user = User::query()->create([
                'person_id' => $person->id,
                'email' => $email,
                'password' => $password,
                'status' => UserStatus::PendingApproval,
                'locale' => $locale,
            ]);
            RegistrationApplication::query()->create([
                'user_id' => $user->id,
                'channel' => 'email',
                'status' => ApplicationStatus::Pending,
                'submitted_at' => now(),
            ]);
            $this->journal->record('identity.registration.submitted', $user, [], ['channel' => 'email']);
            ($this->recordHints)($person);

            return $user;
        });

        event(new Registered($user));

        return $user;
    }
}
