<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Audit\EventJournal;
use App\Domain\Identity\Exceptions\IdentityRuleViolation;
use App\Domain\Identity\Models\SocialIdentity;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The user removes one of their own sign-in methods; the last one cannot be removed.
 */
final readonly class DisconnectProvider
{
    public function __construct(private EventJournal $journal) {}

    public function __invoke(User $user, SocialIdentity $identity): void
    {
        if ($identity->user_id !== $user->id) {
            throw IdentityRuleViolation::identityLinkedElsewhere();
        }
        $others = SocialIdentity::query()->where('user_id', $user->id)->whereKeyNot($identity->id)->count();
        if ($others === 0 && ! filled($user->password)) {
            throw IdentityRuleViolation::lastSignInMethod();
        }

        DB::transaction(function () use ($user, $identity): void {
            $provider = $identity->provider;
            $identity->delete();
            $this->journal->record('identity.provider.unlinked', $user, ['provider' => $provider]);
        });
    }
}
