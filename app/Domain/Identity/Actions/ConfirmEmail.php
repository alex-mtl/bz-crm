<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Audit\EventJournal;
use App\Domain\Identity\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\DB;

final readonly class ConfirmEmail
{
    public function __construct(private EventJournal $journal) {}

    public function __invoke(User $user): void
    {
        if ($user->hasVerifiedEmail()) {
            return;
        }

        DB::transaction(function () use ($user): void {
            $user->markEmailAsVerified();
            $this->journal->record('identity.email.verified', $user);
        });

        event(new Verified($user));
    }
}
