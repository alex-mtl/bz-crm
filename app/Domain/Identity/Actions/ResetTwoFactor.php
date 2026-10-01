<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Access\AuthorizationService;
use App\Domain\Audit\EventJournal;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Support action when a user lost their authenticator: removes 2FA so they can set it up again.
 */
final readonly class ResetTwoFactor
{
    public function __construct(private AuthorizationService $authorization, private EventJournal $journal) {}

    public function __invoke(User $actor, User $target): void
    {
        $this->authorization->authorize($actor, 'users.2fa.reset');

        DB::transaction(function () use ($target): void {
            $target->forceFill(['app_authentication_secret' => null, 'app_authentication_recovery_codes' => null])->save();
            $this->journal->record('identity.two_factor.reset', $target);
        });
    }
}
