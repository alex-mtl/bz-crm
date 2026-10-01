<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Identity\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Terminate all sessions" (ADR-007 п.7): a session whose epoch is older than the user's is signed out.
 */
final class EnsureSessionEpoch
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();
        if ($user instanceof User && $request->hasSession()) {
            $sessionEpoch = $request->session()->get('session_epoch');

            $userEpoch = (int) $user->session_epoch;
            if ($sessionEpoch === null) {
                $request->session()->put('session_epoch', $userEpoch);
            } elseif ((int) $sessionEpoch !== $userEpoch) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()->guest(route('filament.admin.auth.login'));
            }
        }

        return $next($request);
    }
}
