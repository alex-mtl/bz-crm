<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Identity\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Language switch for guests and applicants (ФО §3.6). Active users change it in the profile.
 */
final class LocaleController
{
    public function __invoke(Request $request, string $locale): RedirectResponse
    {
        /** @var list<string> $supported */
        $supported = config('app.supported_locales');
        abort_unless(in_array($locale, $supported, true), 404);

        $request->session()->put('locale', $locale);
        $user = $request->user();
        if ($user instanceof User && $user->mustSeeStatusPage()) {
            $user->update(['locale' => $locale]);
        }

        return back();
    }
}
