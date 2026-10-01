<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Identity\Models\RegistrationApplication;
use App\Domain\Identity\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Landing page after registration and the only page an applicant sees on every sign-in (ФО §6.1).
 */
final class AccountStatusController
{
    public function show(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        assert($user instanceof User);

        if (! $user->mustSeeStatusPage()) {
            return redirect('/admin');
        }

        return view('account.status', [
            'user' => $user,
            'application' => RegistrationApplication::query()->where('user_id', $user->id)->latest('id')->first(),
            'partySiteUrl' => (string) config('app.party_site_url'),
        ]);
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('filament.admin.auth.login');
    }
}
