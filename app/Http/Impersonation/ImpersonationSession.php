<?php

declare(strict_types=1);

namespace App\Http\Impersonation;

use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Impersonations;
use App\Domain\Identity\Models\Impersonation;
use App\Domain\Identity\Models\User;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Auth;

/**
 * Switches the web session to the impersonated account and back (Д-19). Quietly — no Login event, so the target's
 * sign-in history and new-device alerts stay theirs; the impersonation has its own journal entries and notice.
 */
final readonly class ImpersonationSession
{
    public const string KEY = 'impersonation';

    public function __construct(private Impersonations $impersonations) {}

    public function enter(Session $session, Impersonation $impersonation): void
    {
        $session->put(self::KEY, [
            'id' => $impersonation->id,
            'impersonator_id' => $impersonation->impersonator_user_id,
            'impersonator_epoch' => (int) $impersonation->impersonator->session_epoch,
        ]);
        $this->switchTo($session, $impersonation->target);
    }

    /**
     * The impersonation of this session, while it is still valid; null when there is none.
     */
    public function current(Session $session): ?Impersonation
    {
        $data = $session->get(self::KEY);
        if (! is_array($data)) {
            return null;
        }

        $impersonation = Impersonation::query()->find($data['id'] ?? 0);
        $impersonator = $impersonation?->impersonator;
        $valid = $impersonation !== null && $impersonation->isActive()
            && $impersonator?->status === UserStatus::Active
            && (int) $impersonator->session_epoch === (int) ($data['impersonator_epoch'] ?? -1)
            && Auth::guard('web')->id() === $impersonation->target_user_id;

        return $valid ? $impersonation : null;
    }

    public function isActive(Session $session): bool
    {
        return $session->has(self::KEY);
    }

    /**
     * Ends the impersonation and returns the session to the impersonator; signs out if that is no longer possible.
     */
    public function leave(Session $session, string $endReason): ?User
    {
        $data = $session->pull(self::KEY);
        if (! is_array($data)) {
            return null;
        }

        $impersonation = Impersonation::query()->find($data['id'] ?? 0);
        if ($impersonation !== null) {
            $this->impersonations->applyToContext($impersonation);
            $this->impersonations->end($impersonation, $endReason);
        }

        $impersonator = User::query()->find($data['impersonator_id'] ?? 0);
        if ($impersonator?->status === UserStatus::Active && (int) $impersonator->session_epoch === (int) ($data['impersonator_epoch'] ?? -1)) {
            $this->switchTo($session, $impersonator);

            return $impersonator;
        }

        Auth::guard('web')->logout();
        $session->invalidate();
        $session->regenerateToken();

        return null;
    }

    /**
     * The target signed out during an impersonation: the whole session ends, the impersonation with it.
     */
    public function endOnSignOut(Session $session): void
    {
        $data = $session->pull(self::KEY);
        $impersonation = is_array($data) ? Impersonation::query()->find($data['id'] ?? 0) : null;
        if ($impersonation !== null) {
            $this->impersonations->end($impersonation, Impersonation::END_SIGNED_OUT);
        }
    }

    private function switchTo(Session $session, User $user): void
    {
        /** @var SessionGuard $guard */
        $guard = Auth::guard('web');
        $session->put($guard->getName(), $user->getAuthIdentifier());
        $session->forget('password_hash_web');
        $session->put('session_epoch', (int) $user->session_epoch);
        $session->migrate(true);
        $guard->setUser($user);
    }
}
