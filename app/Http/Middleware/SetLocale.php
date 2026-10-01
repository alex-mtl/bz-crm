<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Identity\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Interface language (ФО §3.6): the user's choice, otherwise the guest's session choice, otherwise ro.
 */
final class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var list<string> $supported */
        $supported = config('app.supported_locales');
        $user = Auth::user();

        $locale = match (true) {
            $user instanceof User => $user->locale,
            $request->hasSession() => (string) $request->session()->get('locale', ''),
            default => '',
        };

        app()->setLocale(in_array($locale, $supported, true) ? $locale : $supported[0]);

        return $next($request);
    }
}
