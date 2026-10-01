<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Audit\EventJournal;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Exceptions\IdentityRuleViolation;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Quick sign-in as a demo persona (Д-5): an ordinary sign-in to the persona's own account, not impersonation.
 * Available only in the demo environments and only for accounts on the demo domain; journaled as a demo sign-in.
 */
final readonly class PrepareDemoSignIn
{
    public function __construct(private EventJournal $journal) {}

    public static function available(): bool
    {
        return ! app()->isProduction() && app()->environment((array) config('demo.environments'));
    }

    /**
     * @return Collection<int, User>
     */
    public static function personas(): Collection
    {
        if (! self::available()) {
            return new Collection;
        }

        return User::query()->with('person')
            ->where('email', 'like', '%@'.config('demo.email_domain'))
            ->orderBy('id')
            ->get();
    }

    public function __invoke(User $user): User
    {
        if (! self::available() || ! Str::endsWith((string) $user->email, '@'.config('demo.email_domain'))) {
            throw new IdentityRuleViolation(__('identity.errors.not_demo_persona'));
        }
        if ($user->status === UserStatus::Deactivated) {
            throw new IdentityRuleViolation(__('identity.errors.account_deactivated'));
        }

        $this->journal->record('auth.demo_login', $user);

        return $user;
    }
}
