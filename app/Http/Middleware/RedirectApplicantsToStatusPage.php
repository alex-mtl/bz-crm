<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Identity\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * ФО §6.1: until the application is approved (and the e-mail confirmed), every sign-in
 * leads only to the status page — no internal data, no actions.
 */
final class RedirectApplicantsToStatusPage
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();
        if ($user instanceof User && $user->mustSeeStatusPage()) {
            return redirect()->route('account.status');
        }

        return $next($request);
    }
}
