<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Access\AuthorizationService;
use App\Domain\Audit\EventJournal;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

/**
 * The user edits their own name, interface language and password (ФО §3.6, §6.1).
 */
final readonly class UpdateOwnAccount
{
    public function __construct(private AuthorizationService $authorization, private EventJournal $journal) {}

    public function __invoke(User $user, string $firstName, ?string $lastName, string $locale, #[SensitiveParameter] ?string $newPassword = null): void
    {
        $person = $user->person;
        $this->authorization->authorize($user, 'profile.own.update', $person);

        /** @var list<string> $supported */
        $supported = config('app.supported_locales');
        if (! in_array($locale, $supported, true)) {
            $locale = $user->locale;
        }

        DB::transaction(function () use ($user, $person, $firstName, $lastName, $locale, $newPassword): void {
            $old = ['first_name' => $person->first_name, 'last_name' => $person->last_name, 'locale' => $user->locale];
            $new = ['first_name' => trim($firstName), 'last_name' => $lastName !== null && trim($lastName) !== '' ? trim($lastName) : null, 'locale' => $locale];

            $person->update(['first_name' => $new['first_name'], 'last_name' => $new['last_name'], 'preferred_locale' => $locale]);
            $user->update(['locale' => $locale]);

            if ($old !== $new) {
                $this->journal->record('identity.profile.updated', $user, $old, $new);
            }
            if ($newPassword !== null && $newPassword !== '') {
                $user->update(['password' => $newPassword]);
                $this->journal->record('identity.password.changed', $user);
            }
        });
    }
}
