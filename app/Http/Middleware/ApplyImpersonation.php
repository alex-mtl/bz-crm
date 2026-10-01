<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Identity\Impersonations;
use App\Domain\Identity\Models\Impersonation;
use App\Http\Impersonation\ImpersonationSession;
use Closure;
use Filament\Notifications\Notification;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Д-19: while a session impersonates, every request is journaled as "the impersonator, through the target's account".
 * An expired or revoked impersonation (time limit, the impersonator deactivated or signed out everywhere)
 * returns the session to the impersonator.
 */
final readonly class ApplyImpersonation
{
    public function __construct(private ImpersonationSession $session, private Impersonations $impersonations) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession() || ! $this->session->isActive($request->session())) {
            return $next($request);
        }

        $impersonation = $this->session->current($request->session());
        if ($impersonation === null) {
            $back = $this->session->leave($request->session(), Impersonation::END_EXPIRED);
            if ($back !== null) {
                Notification::make()->title(__('admin.impersonation.expired'))->warning()->send();
            }

            return redirect()->to($back !== null ? '/admin' : route('filament.admin.auth.login'));
        }

        $this->impersonations->applyToContext($impersonation);

        return $next($request);
    }
}
